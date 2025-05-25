<?php
/**
 * Simple test runner for exPricer
 */

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Load autoloader
require_once __DIR__ . '/../src/autoload.php';

use exPricer\Core\PriceCalculator;
use exPricer\Core\Config;

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/tests.log');

// Load configuration
try {
    Config::load();
} catch (\Exception $e) {
    die('Configuration error: ' . $e->getMessage());
}

class TestRunner {
    private $calculator;
    private $results = [
        'passed' => 0,
        'failed' => 0,
        'skipped' => 0,
        'categories' => [
            'unit' => ['passed' => 0, 'failed' => 0],
            'integration' => ['passed' => 0, 'failed' => 0],
            'edge' => ['passed' => 0, 'failed' => 0]
        ]
    ];
    
    public function __construct() {
        $this->calculator = new PriceCalculator();
    }
    
    public function runTests() {
        echo "Running tests...\n\n";
        
        // Unit Tests
        $this->runUnitTests();
        
        // Integration Tests
        $this->runIntegrationTests();
        
        // Edge Case Tests
        $this->runEdgeCaseTests();
        
        $this->printSummary();
    }
    
    private function runUnitTests() {
        echo "<pre><br><br>=== Unit Tests ===<br></pre>";
        
        // Test 1: Basic physical work with no copies sold
        $this->testCase(
            "Physical work, no copies sold",
            [
                'work_type' => 'physical',
                'copies_sold' => 0,
                'max_copies' => 100,
                'min_price' => 100
            ],
            function($result) {
                return $result['current_state']['current_market_price'] === 120.0 &&
                       $result['exclusivity_levels'][0]['price'] === 120.0 &&
                       $result['exclusivity_levels'][count($result['exclusivity_levels'])-1]['price'] === 12000.0;
            },
            'unit'
        );
        
        // Test 2: Digital work with half sold
        $this->testCase(
            "Digital work, half sold",
            [
                'work_type' => 'digital',
                'copies_sold' => 50,
                'max_copies' => 100,
                'min_price' => 100
            ],
            function($result) {
                return $result['current_state']['current_market_price'] === 125.0 &&
                       $result['exclusivity_levels'][0]['price'] === 125.0;
            },
            'unit'
        );
    }
    
    private function runIntegrationTests() {
        echo "<pre><br><br>=== Integration Tests ===<br></pre>";
        
        // Test 3: Small edition (10 copies)
        $this->testCase(
            "Small edition (10 copies)",
            [
                'work_type' => 'physical',
                'copies_sold' => 0,
                'max_copies' => 10,
                'min_price' => 100
            ],
            function($result) {
                return count($result['exclusivity_levels']) >= 4 && // Should have at least 4 options
                       $result['exclusivity_levels'][0]['price'] === 120.0;
            },
            'integration'
        );
        
        // Test 4: Almost sold out
        $this->testCase(
            "Almost sold out",
            [
                'work_type' => 'physical',
                'copies_sold' => 95,
                'max_copies' => 100,
                'min_price' => 100
            ],
            function($result) {
                return $result['current_state']['current_market_price'] === 177.0 &&
                       $result['exclusivity_levels'][0]['price'] === 177.0;
            },
            'integration'
        );
    }
    
    private function runEdgeCaseTests() {
        echo "<pre><br><br>=== Edge Case Tests ===<br></pre>";
        
        // Test 5: Invalid work type
        $this->testCase(
            "Invalid work type",
            [
                'work_type' => 'invalid',
                'copies_sold' => 0,
                'max_copies' => 100,
                'min_price' => 100
            ],
            function($result) { return true; },  // This should never be called
            'edge',
            true  // Expect an exception
        );
        
        // Test 6: Copies sold exceeds max copies
        $this->testCase(
            "Copies sold exceeds max copies",
            [
                'work_type' => 'physical',
                'copies_sold' => 101,
                'max_copies' => 100,
                'min_price' => 100
            ],
            function($result) { return true; },  // This should never be called
            'edge',
            true  // Expect an exception
        );
        
        // Test 7: Negative copies sold
        $this->testCase(
            "Negative copies sold",
            [
                'work_type' => 'physical',
                'copies_sold' => -1,
                'max_copies' => 100,
                'min_price' => 100
            ],
            function($result) { return true; },  // This should never be called
            'edge',
            true  // Expect an exception
        );
        
        // Test 8: Zero max copies
        $this->testCase(
            "Zero max copies",
            [
                'work_type' => 'physical',
                'copies_sold' => 0,
                'max_copies' => 0,
                'min_price' => 100
            ],
            function($result) { return true; },  // This should never be called
            'edge',
            true  // Expect an exception
        );
    }
    
    private function testCase($name, $input, $validator, $category, $expectException = false) {
        echo "<pre>";
        $testOutput = [];
        $testOutput[] = "Test: $name";
        $testOutput[] = "Input: " . json_encode($input, JSON_PRETTY_PRINT);
        $testOutput[] = "Expecting exception: " . ($expectException ? "Yes" : "No");
        
        try {
            $result = $this->calculator->calculatePrices(
                $input['work_type'],
                $input['copies_sold'],
                $input['max_copies'],
                $input['min_price']
            );
            
            if ($expectException) {
                $this->results['failed']++;
                $this->results['categories'][$category]['failed']++;
                $testOutput[] = "❌ Failed: Expected exception but none was thrown";
                echo implode("<br>", $testOutput) . "<br><br>";
                echo "</pre>";
                return;
            }
            
            $testOutput[] = "Output: " . json_encode($result, JSON_PRETTY_PRINT);
            
            if ($validator($result)) {
                $this->results['passed']++;
                $this->results['categories'][$category]['passed']++;
                $testOutput[] = "✅ Passed";
            } else {
                $this->results['failed']++;
                $this->results['categories'][$category]['failed']++;
                $testOutput[] = "❌ Failed: Validation failed";
            }
        } catch (\InvalidArgumentException $e) {
            if ($expectException) {
                $this->results['passed']++;
                $this->results['categories'][$category]['passed']++;
                $testOutput[] = "✅ Passed: Expected exception was thrown";
            } else {
                $this->results['failed']++;
                $this->results['categories'][$category]['failed']++;
                $testOutput[] = "❌ Failed: Unexpected exception: " . $e->getMessage();
            }
        } catch (\Exception $e) {
            $this->results['failed']++;
            $this->results['categories'][$category]['failed']++;
            $testOutput[] = "❌ Failed: Unexpected exception type: " . get_class($e) . " - " . $e->getMessage();
        }
        
        echo implode("<br>", $testOutput) . "<br><br>";
        echo "</pre>";
    }
    
    private function printSummary() {
        echo "<pre>";
        echo "<br><br>=== Test Summary ===<br><br>";
        
        foreach ($this->results['categories'] as $category => $stats) {
            echo "$category Tests:<br>";
            echo "  Passed: {$stats['passed']}<br>";
            echo "  Failed: {$stats['failed']}<br>";
            echo "<br>";
        }
        
        echo "Total:<br>";
        echo "  Passed: {$this->results['passed']}<br>";
        echo "  Failed: {$this->results['failed']}<br>";
        echo "  Skipped: {$this->results['skipped']}<br>";
        echo "</pre>";
        
        // Exit with appropriate status code
        exit($this->results['failed'] > 0 ? 1 : 0);
    }
}

// Run the tests
$runner = new TestRunner();
$runner->runTests(); 