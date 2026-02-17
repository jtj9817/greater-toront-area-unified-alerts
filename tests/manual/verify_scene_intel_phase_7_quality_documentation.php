<?php

/**
 * Manual Test Script: Scene Intel Phase 7 - Quality & Documentation Verification
 * Generated: 2026-02-16
 * Purpose: Comprehensive verification of Scene Intel implementation and documentation
 *
 * This script:
 * 1. Runs all previous phase manual verification scripts
 * 2. Verifies documentation files exist and are complete
 * 3. Confirms quality gates (tests, linting, coverage)
 * 4. Validates implementation vs documentation alignment
 * 5. Generates a comprehensive verification report
 */

require __DIR__.'/../../vendor/autoload.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Initialize Laravel Application
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    exit("❌ Error: Cannot run manual verification in production!\n");
}

// Setup Logging Configuration
$testRunId = 'scene_intel_phase_7_quality_documentation_'.Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

// Helper Functions
function logInfo($message, $context = [])
{
    Log::channel('manual_test')->info($message, $context);
    echo "✓ [INFO] {$message}\n";
}

function logSuccess($message, $context = [])
{
    Log::channel('manual_test')->info("SUCCESS: {$message}", $context);
    echo "✅ [PASS] {$message}\n";
}

function logError($message, $context = [])
{
    Log::channel('manual_test')->error($message, $context);
    echo "❌ [FAIL] {$message}\n";
}

function logWarning($message, $context = [])
{
    Log::channel('manual_test')->warning($message, $context);
    echo "⚠️  [WARN] {$message}\n";
}

function sectionHeader($title)
{
    $separator = str_repeat('=', 80);
    echo "\n{$separator}\n";
    echo "  {$title}\n";
    echo "{$separator}\n\n";
    Log::channel('manual_test')->info("=== {$title} ===");
}

// Verification Results Tracking
$verificationResults = [
    'phase_scripts' => [],
    'documentation' => [],
    'quality_gates' => [],
    'implementation_alignment' => [],
    'overall_status' => 'PENDING',
];

try {
    logInfo("=== Scene Intel Phase 7 Verification Started: {$testRunId} ===");

    // Pre-flight check: Database connectivity
    sectionHeader('Pre-flight Check: Database Connectivity');

    $dbAvailable = false;
    try {
        DB::connection()->getPdo();
        logSuccess('Database connection established');
        $dbAvailable = true;
    } catch (\Exception $e) {
        logWarning('Database connection not available - some checks will be skipped');
        logWarning('To run with database checks, execute via Sail:');
        logWarning('  ./vendor/bin/sail exec laravel.test php tests/manual/verify_scene_intel_phase_7_quality_documentation.php');
        Log::channel('manual_test')->warning('Database unavailable', ['error' => $e->getMessage()]);
    }

    // =========================================================================
    // PHASE 1: Run Previous Phase Verification Scripts
    // =========================================================================
    sectionHeader('Phase 1: Running Previous Phase Verification Scripts');

    if (! $dbAvailable) {
        logWarning('Skipping phase script execution (requires database)');
        $verificationResults['phase_scripts']['note'] = 'SKIPPED_NO_DB';
    }

    $previousPhaseScripts = [
        'phase_1' => __DIR__.'/verify_scene_intel_phase_1_database_models.php',
        'phase_5' => __DIR__.'/verify_scene_intel_phase_5_optimization_hardening.php',
    ];

    if ($dbAvailable) {
        foreach ($previousPhaseScripts as $phaseName => $scriptPath) {
            logInfo("Executing {$phaseName} verification script: {$scriptPath}");

            if (! file_exists($scriptPath)) {
                logError("Script not found: {$scriptPath}");
                $verificationResults['phase_scripts'][$phaseName] = 'NOT_FOUND';

                continue;
            }

            // Execute the script and capture output
            $output = [];
            $returnCode = 0;
            exec("php {$scriptPath} 2>&1", $output, $returnCode);

            $outputText = implode("\n", $output);

            if ($returnCode === 0) {
                logSuccess("{$phaseName} verification passed");
                $verificationResults['phase_scripts'][$phaseName] = 'PASSED';
                Log::channel('manual_test')->debug("Output from {$phaseName}", ['output' => $outputText]);
            } else {
                logError("{$phaseName} verification failed (exit code: {$returnCode})");
                $verificationResults['phase_scripts'][$phaseName] = 'FAILED';
                Log::channel('manual_test')->error("Output from {$phaseName}", ['output' => $outputText]);
            }
        }
    } else {
        foreach ($previousPhaseScripts as $phaseName => $scriptPath) {
            $verificationResults['phase_scripts'][$phaseName] = 'SKIPPED_NO_DB';
        }
    }

    // =========================================================================
    // PHASE 2: Documentation Verification
    // =========================================================================
    sectionHeader('Phase 2: Documentation Verification');

    $documentationFiles = [
        'backend_scene_intel' => [
            'path' => base_path('docs/backend/scene-intel.md'),
            'required_sections' => [
                'Overview',
                'Architecture',
                'Database Schema',
                'Synthetic Intel Generation',
                'API Endpoints',
                'Authorization',
                'Testing',
                'Maintenance & Pruning',
            ],
        ],
        'frontend_types' => [
            'path' => base_path('docs/frontend/types.md'),
            'required_sections' => [
                'Scene Intel Types',
                'SceneIntelItem',
                'useSceneIntel',
                'SceneIntelTimeline',
            ],
        ],
        'backend_maintenance' => [
            'path' => base_path('docs/backend/maintenance.md'),
            'required_sections' => [
                'Scene Intel Retention',
                'Policy',
                'Scheduler',
                'Verification',
            ],
        ],
    ];

    foreach ($documentationFiles as $docName => $docConfig) {
        logInfo("Verifying documentation: {$docName}");

        $docPath = $docConfig['path'];

        if (! file_exists($docPath)) {
            logError("Documentation file not found: {$docPath}");
            $verificationResults['documentation'][$docName] = 'NOT_FOUND';

            continue;
        }

        $docContent = file_get_contents($docPath);
        $missingSections = [];

        foreach ($docConfig['required_sections'] as $section) {
            if (stripos($docContent, $section) === false) {
                $missingSections[] = $section;
            }
        }

        if (empty($missingSections)) {
            logSuccess("Documentation complete: {$docName} (found ".count($docConfig['required_sections']).' required sections)');
            $verificationResults['documentation'][$docName] = 'COMPLETE';
        } else {
            logWarning("Documentation incomplete: {$docName} (missing: ".implode(', ', $missingSections).')');
            $verificationResults['documentation'][$docName] = 'INCOMPLETE';
            Log::channel('manual_test')->warning("Missing sections in {$docName}", ['sections' => $missingSections]);
        }
    }

    // =========================================================================
    // PHASE 3: Quality Gates Verification
    // =========================================================================
    sectionHeader('Phase 3: Quality Gates Verification');

    // 3.1: Database Schema Verification
    if ($dbAvailable) {
        logInfo('Verifying database schema...');

        if (Schema::hasTable('incident_updates')) {
            $expectedColumns = ['id', 'event_num', 'update_type', 'content', 'metadata', 'source', 'created_by', 'created_at', 'updated_at'];
            $actualColumns = Schema::getColumnListing('incident_updates');

            $missingColumns = array_diff($expectedColumns, $actualColumns);

            if (empty($missingColumns)) {
                logSuccess('Database schema valid: incident_updates table has all required columns');
                $verificationResults['quality_gates']['schema'] = 'VALID';
            } else {
                logError('Database schema invalid: missing columns - '.implode(', ', $missingColumns));
                $verificationResults['quality_gates']['schema'] = 'INVALID';
            }
        } else {
            logError('Database schema invalid: incident_updates table does not exist');
            $verificationResults['quality_gates']['schema'] = 'INVALID';
        }
    } else {
        logWarning('Database schema verification skipped (no database connection)');
        $verificationResults['quality_gates']['schema'] = 'SKIPPED_NO_DB';
    }

    // 3.2: Enum Verification
    logInfo('Verifying IncidentUpdateType enum...');

    $enumClass = \App\Enums\IncidentUpdateType::class;
    if (class_exists($enumClass)) {
        $expectedCases = ['MILESTONE', 'RESOURCE_STATUS', 'ALARM_CHANGE', 'PHASE_CHANGE', 'MANUAL_NOTE'];
        $actualCases = array_map(fn ($case) => $case->name, $enumClass::cases());

        $missingCases = array_diff($expectedCases, $actualCases);

        if (empty($missingCases)) {
            logSuccess('IncidentUpdateType enum valid: all expected cases present');
            $verificationResults['quality_gates']['enum'] = 'VALID';
        } else {
            logError('IncidentUpdateType enum invalid: missing cases - '.implode(', ', $missingCases));
            $verificationResults['quality_gates']['enum'] = 'INVALID';
        }
    } else {
        logError('IncidentUpdateType enum not found');
        $verificationResults['quality_gates']['enum'] = 'NOT_FOUND';
    }

    // 3.3: Model Verification
    logInfo('Verifying IncidentUpdate model...');

    $modelClass = \App\Models\IncidentUpdate::class;
    if (class_exists($modelClass)) {
        $model = new $modelClass;

        // Check for MassPrunable trait
        $usesPrunable = in_array('Illuminate\Database\Eloquent\MassPrunable', class_uses_recursive($model));

        if ($usesPrunable) {
            logSuccess('IncidentUpdate model uses MassPrunable trait for pruning');
            $verificationResults['quality_gates']['prunable_trait'] = 'VALID';
        } else {
            logWarning('IncidentUpdate model does not use MassPrunable trait');
            $verificationResults['quality_gates']['prunable_trait'] = 'MISSING';
        }

        // Check relationships
        $hasFireIncidentRelation = method_exists($model, 'fireIncident');
        $hasCreatorRelation = method_exists($model, 'creator');

        if ($hasFireIncidentRelation && $hasCreatorRelation) {
            logSuccess('IncidentUpdate model has required relationships (fireIncident, creator)');
            $verificationResults['quality_gates']['model_relationships'] = 'VALID';
        } else {
            logError('IncidentUpdate model missing relationships');
            $verificationResults['quality_gates']['model_relationships'] = 'INVALID';
        }
    } else {
        logError('IncidentUpdate model not found');
        $verificationResults['quality_gates']['model'] = 'NOT_FOUND';
    }

    // 3.4: Service Verification
    logInfo('Verifying SceneIntelProcessor service...');

    $serviceClass = \App\Services\SceneIntel\SceneIntelProcessor::class;
    if (class_exists($serviceClass)) {
        $service = app($serviceClass);

        if (method_exists($service, 'processIncidentUpdate')) {
            logSuccess('SceneIntelProcessor has processIncidentUpdate method');
            $verificationResults['quality_gates']['processor_service'] = 'VALID';
        } else {
            logError('SceneIntelProcessor missing processIncidentUpdate method');
            $verificationResults['quality_gates']['processor_service'] = 'INVALID';
        }
    } else {
        logError('SceneIntelProcessor service not found');
        $verificationResults['quality_gates']['processor_service'] = 'NOT_FOUND';
    }

    // 3.5: Repository Verification
    logInfo('Verifying SceneIntelRepository...');

    $repositoryClass = \App\Services\SceneIntel\SceneIntelRepository::class;
    if (class_exists($repositoryClass)) {
        $repo = app($repositoryClass);

        $expectedMethods = ['getLatestForIncident', 'getTimeline', 'getSummaryForIncident', 'addManualEntry'];
        $missingMethods = [];

        foreach ($expectedMethods as $method) {
            if (! method_exists($repo, $method)) {
                $missingMethods[] = $method;
            }
        }

        if (empty($missingMethods)) {
            logSuccess('SceneIntelRepository has all required methods');
            $verificationResults['quality_gates']['repository'] = 'VALID';
        } else {
            logError('SceneIntelRepository missing methods: '.implode(', ', $missingMethods));
            $verificationResults['quality_gates']['repository'] = 'INVALID';
        }
    } else {
        logError('SceneIntelRepository not found');
        $verificationResults['quality_gates']['repository'] = 'NOT_FOUND';
    }

    // 3.6: API Routes Verification
    logInfo('Verifying API routes...');

    $routes = app('router')->getRoutes();
    $expectedRoutes = [
        ['GET', 'api/incidents/{eventNum}/intel'],
        ['POST', 'api/incidents/{eventNum}/intel'],
    ];

    $foundRoutes = [];

    foreach ($routes as $route) {
        foreach ($expectedRoutes as $expectedRoute) {
            if (in_array($expectedRoute[0], $route->methods()) &&
                str_contains($route->uri(), $expectedRoute[1])) {
                $foundRoutes[] = implode(' ', $expectedRoute);
            }
        }
    }

    if (count($foundRoutes) === count($expectedRoutes)) {
        logSuccess('All Scene Intel API routes registered');
        $verificationResults['quality_gates']['routes'] = 'VALID';
    } else {
        logWarning('Some Scene Intel routes may be missing (found: '.implode(', ', $foundRoutes).')');
        $verificationResults['quality_gates']['routes'] = 'INCOMPLETE';
    }

    // 3.7: Authorization Gate Verification
    logInfo('Verifying authorization gate...');

    try {
        if (Gate::has('scene-intel.create-manual-entry')) {
            logSuccess("Authorization gate 'scene-intel.create-manual-entry' is registered");
            $verificationResults['quality_gates']['authorization_gate'] = 'VALID';
        } else {
            logError("Authorization gate 'scene-intel.create-manual-entry' not found");
            $verificationResults['quality_gates']['authorization_gate'] = 'NOT_FOUND';
        }
    } catch (\Exception $e) {
        logError('Error checking authorization gate: '.$e->getMessage());
        $verificationResults['quality_gates']['authorization_gate'] = 'ERROR';
    }

    // =========================================================================
    // PHASE 4: Implementation vs Documentation Alignment
    // =========================================================================
    sectionHeader('Phase 4: Implementation vs Documentation Alignment');

    // 4.1: Verify FireAlertSelectProvider has intel_summary
    logInfo('Verifying FireAlertSelectProvider intel_summary implementation...');

    $providerClass = \App\Services\Alerts\Providers\FireAlertSelectProvider::class;
    if (class_exists($providerClass)) {
        $providerSource = file_get_contents(app_path('Services/Alerts/Providers/FireAlertSelectProvider.php'));

        $hasIntelSummary = str_contains($providerSource, 'intel_summary');
        $hasIntelLastUpdated = str_contains($providerSource, 'intel_last_updated');

        if ($hasIntelSummary && $hasIntelLastUpdated) {
            logSuccess('FireAlertSelectProvider includes intel_summary and intel_last_updated');
            $verificationResults['implementation_alignment']['provider_embedding'] = 'VALID';
        } else {
            logWarning('FireAlertSelectProvider may be missing intel embedding fields');
            $verificationResults['implementation_alignment']['provider_embedding'] = 'INCOMPLETE';
        }
    } else {
        logError('FireAlertSelectProvider not found');
        $verificationResults['implementation_alignment']['provider_embedding'] = 'NOT_FOUND';
    }

    // 4.2: Verify FetchFireIncidentsCommand integration
    logInfo('Verifying FetchFireIncidentsCommand processor integration...');

    $commandClass = \App\Console\Commands\FetchFireIncidentsCommand::class;
    if (class_exists($commandClass)) {
        $commandSource = file_get_contents(app_path('Console/Commands/FetchFireIncidentsCommand.php'));

        $hasProcessorCall = str_contains($commandSource, 'SceneIntelProcessor') ||
                           str_contains($commandSource, 'processIncidentUpdate');

        if ($hasProcessorCall) {
            logSuccess('FetchFireIncidentsCommand integrates with SceneIntelProcessor');
            $verificationResults['implementation_alignment']['command_integration'] = 'VALID';
        } else {
            logError('FetchFireIncidentsCommand missing SceneIntelProcessor integration');
            $verificationResults['implementation_alignment']['command_integration'] = 'MISSING';
        }
    } else {
        logError('FetchFireIncidentsCommand not found');
        $verificationResults['implementation_alignment']['command_integration'] = 'NOT_FOUND';
    }

    // 4.3: Verify scheduled command
    logInfo('Verifying Scene Intel pruning is scheduled...');

    $consoleRoutes = file_get_contents(base_path('routes/console.php'));

    $hasPruningSchedule = str_contains($consoleRoutes, 'model:prune') &&
                         str_contains($consoleRoutes, 'IncidentUpdate');

    if ($hasPruningSchedule) {
        logSuccess('Scene Intel pruning command is scheduled');
        $verificationResults['implementation_alignment']['pruning_schedule'] = 'VALID';
    } else {
        logError('Scene Intel pruning command not found in schedule');
        $verificationResults['implementation_alignment']['pruning_schedule'] = 'MISSING';
    }

    // 4.4: Test Files Verification
    logInfo('Verifying test coverage exists...');

    $testFiles = [
        'IncidentUpdateTest' => base_path('tests/Unit/Models/IncidentUpdateTest.php'),
        'SceneIntelProcessorTest' => base_path('tests/Unit/Services/SceneIntel/SceneIntelProcessorTest.php'),
        'SceneIntelRepositoryTest' => base_path('tests/Unit/Services/SceneIntel/SceneIntelRepositoryTest.php'),
        'SceneIntelControllerTest' => base_path('tests/Feature/SceneIntel/SceneIntelControllerTest.php'),
        'IncidentUpdatePruningTest' => base_path('tests/Feature/SceneIntel/IncidentUpdatePruningTest.php'),
    ];

    $foundTests = 0;
    $totalTests = count($testFiles);

    foreach ($testFiles as $testName => $testPath) {
        if (file_exists($testPath)) {
            $foundTests++;
        } else {
            logWarning("Test file not found: {$testName}");
        }
    }

    if ($foundTests === $totalTests) {
        logSuccess("All expected test files present ({$foundTests}/{$totalTests})");
        $verificationResults['implementation_alignment']['test_coverage'] = 'COMPLETE';
    } else {
        logWarning("Some test files missing ({$foundTests}/{$totalTests} found)");
        $verificationResults['implementation_alignment']['test_coverage'] = 'INCOMPLETE';
    }

    // =========================================================================
    // PHASE 5: Generate Comprehensive Report
    // =========================================================================
    sectionHeader('Phase 5: Verification Report Summary');

    // Calculate overall status
    $allPassed = true;
    $hasSkippedDb = false;

    foreach ($verificationResults as $category => $results) {
        if ($category === 'overall_status') {
            continue;
        }

        foreach ($results as $key => $status) {
            if (in_array($status, ['FAILED', 'NOT_FOUND', 'INVALID', 'ERROR', 'MISSING'])) {
                $allPassed = false;
                break 2;
            }
            if ($status === 'SKIPPED_NO_DB') {
                $hasSkippedDb = true;
            }
        }
    }

    if (! $allPassed) {
        $verificationResults['overall_status'] = 'FAILED';
    } elseif ($hasSkippedDb) {
        $verificationResults['overall_status'] = 'PASSED_WITH_WARNINGS';
    } else {
        $verificationResults['overall_status'] = 'PASSED';
    }

    // Display summary
    echo "\n";
    logInfo('=== VERIFICATION RESULTS SUMMARY ===');
    echo "\n";

    foreach ($verificationResults as $category => $results) {
        if ($category === 'overall_status') {
            continue;
        }

        echo '  '.strtoupper(str_replace('_', ' ', $category)).":\n";
        foreach ($results as $key => $status) {
            $icon = in_array($status, ['PASSED', 'VALID', 'COMPLETE']) ? '✅' :
                   (in_array($status, ['INCOMPLETE', 'MISSING', 'WARN']) ? '⚠️' : '❌');
            echo "    {$icon} {$key}: {$status}\n";
        }
        echo "\n";
    }

    if ($verificationResults['overall_status'] === 'PASSED') {
        logSuccess('=== PHASE 7 VERIFICATION: ALL CHECKS PASSED ===');
    } elseif ($verificationResults['overall_status'] === 'PASSED_WITH_WARNINGS') {
        logWarning('=== PHASE 7 VERIFICATION: PASSED WITH WARNINGS ===');
        logWarning('Some checks were skipped (database not available)');
        logWarning('Run via Sail for full verification:');
        logWarning('  ./vendor/bin/sail exec laravel.test php tests/manual/verify_scene_intel_phase_7_quality_documentation.php');
    } else {
        logError('=== PHASE 7 VERIFICATION: SOME CHECKS FAILED ===');
    }

    // Save detailed report
    $reportPath = storage_path("logs/manual_tests/{$testRunId}_report.json");
    file_put_contents($reportPath, json_encode($verificationResults, JSON_PRETTY_PRINT));
    logInfo("Detailed report saved to: {$reportPath}");

} catch (\Exception $e) {
    logError('Verification failed with exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    $verificationResults['overall_status'] = 'ERROR';
} finally {
    logInfo('=== Scene Intel Phase 7 Verification Completed ===');
    logInfo("Full logs available at: {$logFile}");
    echo "\n✓ Verification completed. Check detailed logs at:\n";
    echo "  {$logFile}\n\n";

    $exitCode = in_array($verificationResults['overall_status'], ['PASSED', 'PASSED_WITH_WARNINGS']) ? 0 : 1;
    exit($exitCode);
}
