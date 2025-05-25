<?php
namespace exPricer\Core;

class PriceCalculator {
    private const WORK_TYPE_PHYSICAL = 'physical';
    private const WORK_TYPE_DIGITAL = 'digital';
    
    private const WORK_TYPE_FACTORS = [
        self::WORK_TYPE_PHYSICAL => 1.2,
        self::WORK_TYPE_DIGITAL => 1.0
    ];
    
    /**
     * Get exclusivity options based on current state
     */
    private function getExclusivityOptions(int $maxCopies, int $copiesSold): array {
        $remainingCopies = $maxCopies - $copiesSold;
        
        // Always include the current remaining copies as the first option
        $options = [$remainingCopies];
        
        // Add options for reducing remaining copies
        if ($remainingCopies > 1) {
            // For small editions (≤10), offer more granular options
            if ($maxCopies <= 10) {
                $options = array_merge($options, [1, 2, 3]);
            } else {
                // For larger editions, offer percentage-based reductions
                $percentages = [0.75, 0.5, 0.25, 0.1];
                foreach ($percentages as $percentage) {
                    $reducedCopies = max(1, floor($remainingCopies * $percentage));
                    if ($reducedCopies < $remainingCopies) {
                        $options[] = $reducedCopies;
                    }
                }
            }
        }
        
        // Always include the option to be the last copy
        if (!in_array(1, $options)) {
            $options[] = 1;
        }
        
        // Remove any options that are greater than remaining copies
        $options = array_filter($options, function($option) use ($remainingCopies) {
            return $option <= $remainingCopies;
        });
        
        // Sort options in descending order (most exclusive first)
        rsort($options);
        
        return array_unique($options);
    }
    
    /**
     * Calculate prices for different exclusivity levels
     *
     * @param string $workType 'physical' or 'digital'
     * @param int $copiesSold Number of copies already sold
     * @param int $maxCopies Maximum number of copies allowed
     * @param float $minPrice Minimum acceptable price
     * @return array Array of exclusivity levels with prices
     */
    public function calculatePrices(
        string $workType,
        int $copiesSold,
        int $maxCopies,
        float $minPrice
    ): array {
        // Validate inputs
        $this->validateInputs($workType, $copiesSold, $maxCopies, $minPrice);
        
        $remainingCopies = $maxCopies - $copiesSold;
        $exclusivityOptions = $this->getExclusivityOptions($maxCopies, $copiesSold);
        $prices = [];
        
        // Calculate current market price
        $workTypeFactor = self::WORK_TYPE_FACTORS[$workType];
        $marketFactor = 1 + ($copiesSold / $maxCopies) * 0.5;
        $currentMarketPrice = round($minPrice * $workTypeFactor * $marketFactor, 2);
        
        foreach ($exclusivityOptions as $level) {
            if ($level <= $remainingCopies) {
                $price = $this->calculatePriceForLevel(
                    $workType,
                    $level,
                    $remainingCopies,
                    $minPrice,
                    $copiesSold,
                    $maxCopies,
                    $currentMarketPrice
                );
                
                $prices[] = [
                    'remaining_copies' => $level,
                    'price' => $price,
                    'percentage_of_edition' => round(($level / $maxCopies) * 100, 1),
                    'is_last_copy' => $level === 1,
                    'is_current_level' => $level === $remainingCopies
                ];
            }
        }
        
        return [
            'exclusivity_levels' => $prices,
            'current_state' => [
                'total_copies' => $maxCopies,
                'copies_sold' => $copiesSold,
                'copies_remaining' => $remainingCopies,
                'work_type' => $workType,
                'min_price' => $minPrice,
                'work_type_factor' => $workTypeFactor,
                'current_market_price' => $currentMarketPrice
            ]
        ];
    }
    
    /**
     * Calculate price for a specific exclusivity level
     */
    private function calculatePriceForLevel(
        string $workType,
        int $level,
        int $remainingCopies,
        float $minPrice,
        int $copiesSold,
        int $maxCopies,
        float $currentMarketPrice
    ): float {
        if ($level === $remainingCopies) {
            // This is the current normal price
            return round($currentMarketPrice, 2);
        }
        
        // Calculate the value of exclusivity
        // The more copies we eliminate, the higher the price should be
        $eliminatedCopies = $remainingCopies - $level;
        
        // Base price is the current market price
        // Add the value of all eliminated copies at current market price
        $exclusivityValue = $currentMarketPrice * $eliminatedCopies;
        
        return round($currentMarketPrice + $exclusivityValue, 2);
    }
    
    /**
     * Validate input parameters
     */
    private function validateInputs(
        string $workType,
        int $copiesSold,
        int $maxCopies,
        float $minPrice
    ): void {
        if (!in_array($workType, [self::WORK_TYPE_PHYSICAL, self::WORK_TYPE_DIGITAL])) {
            throw new \InvalidArgumentException('Invalid work type: ' . $workType);
        }
        
        if ($maxCopies <= 0) {
            throw new \InvalidArgumentException('Maximum copies must be greater than 0');
        }
        
        if ($minPrice <= 0) {
            throw new \InvalidArgumentException('Minimum price must be greater than 0');
        }
        
        if ($copiesSold < 0) {
            throw new \InvalidArgumentException('Copies sold cannot be negative');
        }
        
        if ($copiesSold > $maxCopies) {
            throw new \InvalidArgumentException('Copies sold cannot exceed maximum copies');
        }
    }
} 