<?php
namespace exPricer\Core;

class SalesTracker {
    private $stateFile;
    private $state;
    
    public function __construct() {
        $this->stateFile = __DIR__ . '/../../data/sales_state.json';
        $this->loadState();
    }
    
    private function loadState() {
        // Create data directory if it doesn't exist
        $dataDir = dirname($this->stateFile);
        if (!file_exists($dataDir)) {
            if (!mkdir($dataDir, 0755, true)) {
                throw new \RuntimeException('Failed to create data directory: ' . $dataDir);
            }
        }
        
        // Check directory permissions
        if (!is_writable($dataDir)) {
            throw new \RuntimeException('Data directory is not writable: ' . $dataDir);
        }
        
        // Initialize state if file doesn't exist
        if (!file_exists($this->stateFile)) {
            $this->state = [
                'copies_sold' => 0,
                'sales_history' => [],
                'sessions' => [],
                'total_sales' => 0
            ];
            $this->saveState();
        } else {
            $content = file_get_contents($this->stateFile);
            if ($content === false) {
                throw new \RuntimeException('Failed to read sales state file');
            }
            
            $this->state = json_decode($content, true);
            if ($this->state === null) {
                // If JSON is invalid, start with a fresh state
                $this->state = [
                    'copies_sold' => 0,
                    'sales_history' => [],
                    'sessions' => [],
                    'total_sales' => 0
                ];
                $this->saveState();
            } else {
                // Initialize missing fields with default values
                $this->state = array_merge([
                    'copies_sold' => 0,
                    'sales_history' => [],
                    'sessions' => [],
                    'total_sales' => 0
                ], $this->state);
                
                // Ensure all required fields are arrays
                if (!is_array($this->state['sales_history'])) {
                    $this->state['sales_history'] = [];
                }
                if (!is_array($this->state['sessions'])) {
                    $this->state['sessions'] = [];
                }
                
                // Save the updated state to ensure all fields are present
                $this->saveState();
            }
        }
    }
    
    private function saveState() {
        // Ensure file is writable
        if (file_exists($this->stateFile) && !is_writable($this->stateFile)) {
            throw new \RuntimeException('Sales state file is not writable: ' . $this->stateFile);
        }
        
        $content = json_encode($this->state, JSON_PRETTY_PRINT);
        if ($content === false) {
            throw new \RuntimeException('Failed to encode sales state to JSON');
        }
        
        if (file_put_contents($this->stateFile, $content) === false) {
            throw new \RuntimeException('Failed to save sales state to: ' . $this->stateFile);
        }
    }
    
    public function getCopiesSold(): int {
        return $this->state['copies_sold'];
    }
    
    public function recordSale($copiesEliminated, $customerEmail, $amount, $sessionId = null) {
        try {
            if ($copiesEliminated <= 0) {
                throw new \InvalidArgumentException('Copies eliminated must be greater than 0');
            }

            // Check if this session has already been recorded
            if ($sessionId && in_array($sessionId, $this->state['sessions'])) {
                return; // Skip recording if this session was already processed
            }
            
            // Record the sale
            $this->state['total_sales'] = ($this->state['total_sales'] ?? 0) + $amount;
            $this->state['copies_sold'] = ($this->state['copies_sold'] ?? 0) + $copiesEliminated;
            
            // Add to sales history
            $this->state['sales_history'][] = [
                'timestamp' => time(),
                'copies_eliminated' => $copiesEliminated,
                'customer_email' => $customerEmail,
                'price' => $amount,
                'session_id' => $sessionId
            ];
            
            // Add the session ID to the list of processed sessions
            if ($sessionId) {
                $this->state['sessions'][] = $sessionId;
            }
            
            // Save the updated state
            $this->saveState();
            
        } catch (\Exception $e) {
            error_log("Error in recordSale: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function getSalesHistory(): array {
        return $this->state['sales_history'];
    }
    
    public function getSalesData(): array {
        return $this->state;
    }
    
    public function resetState(): void {
        $this->state = [
            'copies_sold' => 0,
            'sales_history' => [],
            'sessions' => [],
            'total_sales' => 0
        ];
        $this->saveState();
    }
} 