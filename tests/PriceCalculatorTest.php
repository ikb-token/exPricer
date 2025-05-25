<?php
namespace exPricer\Tests;

use exPricer\Core\PriceCalculator;
use PHPUnit\Framework\TestCase;

class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calculator;
    
    protected function setUp(): void
    {
        $this->calculator = new PriceCalculator();
    }
    
    public function testCalculatePricesForPhysicalWork()
    {
        $result = $this->calculator->calculatePrices(
            'physical',
            10,
            2,
            20,
            100
        );
        
        $this->assertArrayHasKey('exclusivity_levels', $result);
        $this->assertNotEmpty($result['exclusivity_levels']);
        
        // Test that prices increase with exclusivity
        $prices = array_column($result['exclusivity_levels'], 'price');
        $this->assertTrue($prices[0] > $prices[1]); // Price for 1 copy > price for 3 copies
    }
    
    public function testCalculatePricesForDigitalWork()
    {
        $result = $this->calculator->calculatePrices(
            'digital',
            10,
            2,
            20,
            100
        );
        
        $this->assertArrayHasKey('exclusivity_levels', $result);
        $this->assertNotEmpty($result['exclusivity_levels']);
    }
    
    public function testInvalidWorkType()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        $this->calculator->calculatePrices(
            'invalid',
            10,
            2,
            20,
            100
        );
    }
    
    public function testInvalidCopiesProduced()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        $this->calculator->calculatePrices(
            'physical',
            5,
            10,
            20,
            100
        );
    }
} 