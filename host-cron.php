<?php

/**
 * Cron Job Runner for Laravel Scheduled Commands
 * 
 * This file automatically runs Laravel's task scheduler (schedule:run)
 * when executed. Perfect for cron jobs on servers that don't allow
 * direct execution of the artisan file or don't have proc_open enabled.
 * 
 * Usage in cron (runs every minute):
 * * * * * * php /path/to/project/cron.php
 * 
 * Or if your server requires full path to PHP:
 * * * * * * /usr/bin/php /path/to/project/cron.php
 */

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader
| for our application. We just need to utilize it! We'll require it
| into the script here so that we do not have to worry about the
| loading of any of our classes manually. It's great to relax.
|
*/

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

// Use Laravel's Log facade for better logging
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Run The Scheduled Tasks
|--------------------------------------------------------------------------
|
| This script runs Laravel's scheduled tasks directly without using
| proc_open, which is not available on all PHP installations.
| It directly instantiates and runs command classes.
|
*/

// Bootstrap the application
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Get the schedule instance
$schedule = $app->make(Illuminate\Console\Scheduling\Schedule::class);

// Populate the schedule by calling the Kernel's schedule method
// We need to use reflection to call the protected schedule method
$kernelReflection = new ReflectionClass($kernel);
$scheduleMethod = $kernelReflection->getMethod('schedule');
$scheduleMethod->setAccessible(true);
$scheduleMethod->invoke($kernel, $schedule);

// Get all scheduled events
$events = $schedule->events();

// Debug: Log how many events were found
Log::info('Cron: Found ' . count($events) . ' scheduled events');

$eventsRan = 0;

// Run each scheduled event that is due
foreach ($events as $event) {
    $isDue = $event->isDue($app);
    
    // Debug: Log if event is due
    if ($isDue) {
        Log::info('Cron: Event is due, processing...');
    }
    
    if ($isDue) {
        try {
            // Use reflection to get the command string from the event
            $reflection = new ReflectionClass($event);
            
            // Try to get the command property
            $command = null;
            if ($reflection->hasProperty('command')) {
                $commandProperty = $reflection->getProperty('command');
                $commandProperty->setAccessible(true);
                $command = $commandProperty->getValue($event);
            }
            
            if ($command) {
                // Parse command and options
                // Format can be:
                // - "command:name --option1 --option2=value"
                // - "php artisan command:name --option"
                // - "/path/to/php artisan command:name --option"
                // - "'/path/to/php' 'artisan' command:name --option" (with quotes)
                // Remove PHP path and "artisan" if present (handle both quoted and unquoted)
                $originalCommand = $command;
                
                Log::info('Cron: Original command: ' . $originalCommand);
                
                // Split the command string properly handling quoted strings
                // The command format is: '/path/to/php' 'artisan' command:name --options
                $parts = [];
                $current = '';
                $inQuotes = false;
                $quoteChar = '';
                
                for ($i = 0; $i < strlen($command); $i++) {
                    $char = $command[$i];
                    
                    if (($char === "'" || $char === '"') && ($i === 0 || $command[$i-1] === ' ' || $command[$i-1] === '\'' || $command[$i-1] === '"')) {
                        if (!$inQuotes) {
                            $inQuotes = true;
                            $quoteChar = $char;
                        } elseif ($char === $quoteChar) {
                            $inQuotes = false;
                            $quoteChar = '';
                        }
                        continue;
                    }
                    
                    if ($char === ' ' && !$inQuotes) {
                        if ($current !== '') {
                            $parts[] = $current;
                            $current = '';
                        }
                    } else {
                        $current .= $char;
                    }
                }
                
                if ($current !== '') {
                    $parts[] = $current;
                }
                
                // Filter out PHP path and artisan
                $parts = array_filter($parts, function($part) {
                    $clean = trim($part, '\'"');
                    // Skip if it's a PHP path (contains 'php' and '/') or 'artisan'
                    if (strpos($clean, 'php') !== false && strpos($clean, '/') !== false) {
                        return false;
                    }
                    if ($clean === 'artisan') {
                        return false;
                    }
                    return true;
                });
                
                $parts = array_values($parts);
                $commandName = $parts[0] ?? null;
                
                Log::info('Cron: Parsed parts: ' . json_encode($parts));
                Log::info('Cron: Extracted command name: ' . ($commandName ?? 'null'));
                
                if ($commandName) {
                    // Extract options
                    $options = [];
                    for ($i = 1; $i < count($parts); $i++) {
                        $part = $parts[$i];
                        if (strpos($part, '--') === 0) {
                            $option = substr($part, 2);
                            if (strpos($option, '=') !== false) {
                                list($key, $value) = explode('=', $option, 2);
                                $options[$key] = $value;
                            } else {
                                $options[$option] = true;
                            }
                        }
                    }
                    
                    // Map command names to their class names
                    $commandMap = [
                        'inventory:crawl-sources' => 'App\Console\Commands\CrawlInventorySources',
                        'products:crawl-external-catalogs' => 'App\Console\Commands\CrawlExternalCatalogs',
                        'products:check-digikala-prices' => 'App\Console\Commands\CheckDigikalaPrices',
                        'orders:sync-digikala' => 'App\Console\Commands\SyncDigikalaOrders',
                        'orders:sync-digikala-active' => 'App\Console\Commands\SyncDigikalaActiveOrders',
                        'invoices:sync-digikala' => 'App\Console\Commands\SyncDigikalaInvoices',
                        'invoices:sync-digikala-details' => 'App\Console\Commands\SyncDigikalaInvoiceDetails',
                        'statistics:purge' => 'App\Console\Commands\PurgeStatistics',
                        'cart:maintain' => 'App\Console\Commands\MaintainCart',
                        'orders:flag-preparation-timeout' => 'App\Console\Commands\FlagOrdersInPreparation',
                    ];
                    
                    // Get the command class from the map
                    if (isset($commandMap[$commandName])) {
                        $commandClass = $commandMap[$commandName];
                        
                        // Resolve the command from the container (handles dependencies)
                        $commandInstance = $app->make($commandClass);
                        
                        // Create input array with only options (no command name needed)
                        // The command definition doesn't include a "command" argument
                        $inputArray = [];
                        foreach ($options as $key => $value) {
                            if ($value === true) {
                                // For boolean flags, just include the key
                                $inputArray['--' . $key] = true;
                            } else {
                                $inputArray['--' . $key] = $value;
                            }
                        }
                        
                        // Get the command's definition and extend it with standard Laravel options
                        $commandDefinition = $commandInstance->getDefinition();
                        
                        // Clone the command's definition to avoid modifying the original
                        $fullDefinition = new Symfony\Component\Console\Input\InputDefinition();
                        
                        // Add all arguments from command definition
                        foreach ($commandDefinition->getArguments() as $argument) {
                            $fullDefinition->addArgument($argument);
                        }
                        
                        // Add all options from command definition
                        foreach ($commandDefinition->getOptions() as $option) {
                            $fullDefinition->addOption($option);
                        }
                        
                        // Add standard Laravel console options if they don't already exist
                        $existingOptionNames = array_map(function($opt) { return $opt->getName(); }, $fullDefinition->getOptions());
                        
                        if (!in_array('verbose', $existingOptionNames)) {
                            $fullDefinition->addOption(new Symfony\Component\Console\Input\InputOption('verbose', 'v', Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Increase the verbosity of messages'));
                        }
                        if (!in_array('quiet', $existingOptionNames)) {
                            $fullDefinition->addOption(new Symfony\Component\Console\Input\InputOption('quiet', 'q', Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Do not output any message'));
                        }
                        if (!in_array('no-interaction', $existingOptionNames)) {
                            $fullDefinition->addOption(new Symfony\Component\Console\Input\InputOption('no-interaction', 'n', Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Do not ask any interactive question'));
                        }
                        
                        // For VALUE_NONE options, we don't need to add them to inputArray
                        // They default to false if not present
                        // Only add --no-interaction since we want it to be true
                        if (!isset($inputArray['--no-interaction']) && !isset($inputArray['-n'])) {
                            $inputArray['--no-interaction'] = true;
                        }
                        
                        // Create ArrayInput with the extended definition
                        // ArrayInput automatically binds the input to the definition in its constructor
                        $input = new Symfony\Component\Console\Input\ArrayInput(
                            $inputArray,
                            $fullDefinition
                        );
                        $input->setInteractive(false);
                        
                        // Log for debugging
                        Log::info('Cron: Input definition has ' . count($fullDefinition->getOptions()) . ' options: ' . implode(', ', array_map(function($opt) { return $opt->getName(); }, $fullDefinition->getOptions())));
                        
                        // Create output using Laravel's OutputStyle (required by setOutput)
                        $nullOutput = new Symfony\Component\Console\Output\NullOutput();
                        $output = new Illuminate\Console\OutputStyle($input, $nullOutput);
                        
                        // Set input and output on the command
                        $commandInstance->setInput($input);
                        $commandInstance->setOutput($output);
                        
                        // Call handle() directly - this doesn't use Process
                        Log::info('Cron: Executing command: ' . $commandName . ' with options: ' . json_encode($options));
                        $exitCode = $commandInstance->handle();
                        Log::info('Cron: Command executed with exit code: ' . $exitCode);
                        $eventsRan++;
                    } else {
                        Log::warning('Cron: Command not found in map: ' . $commandName);
                    }
                }
            }
        } catch (Exception $e) {
            // Log error but continue with other events
            Log::error('Scheduled task failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}

// Debug: Log final status
Log::info('Cron: Completed. Events ran: ' . $eventsRan . ' out of ' . count($events) . ' total events');

exit(0);
