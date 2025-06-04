# exPricer

exPricer is a dynamic pricing system for art works that adjusts prices based on exclusivity. Buyers of a work can choose to pay more for more exclusivity, reducing (or even eliminating) any other copies for sale after their purchase.

This project includes both a dynamic pricing algorithm API as well as a ready-to-use web checkout and payment system (powered by Stripe) that uses the same dynamic pricing algorithm. The web checkout system is meant for digital artists who are looking to sell a limited number of a specific work, such as an image, a music file, or any other document, even a ZIP file (which can contain multiple files, such as a music album).

This project was developed by the [International Klein Blue token collective](https://ikb-token.co). We are a collective of techno-artists inspired by the work of Yves Klein, in particular the questions he asked about how we value art, how art is priced, etc. Our collective is federated by a community token on the Solana blockchain with the symbol [IKB](https://ikb-token.co).

## License

<img alt="CC BY 4.0 License" src="https://mirrors.creativecommons.org/presskit/buttons/88x31/png/by.png" width="150" />

This project can be freely used by all and is licensed under the [Creative Commons Attribution 4.0 International license](https://creativecommons.org/licenses/by/4.0/) (CC BY 4.0). When using this project code, you must include attribution to "International Klein Blue (IKB) token collective - ikb-token.co" somewhere on your website.

## Requirements

- A web hosting account or personal web server that has PHP 7.4 or higher
- If you wish to use the web checkout system, you will need to have a Stripe account; it's not necessary if you are interested in just using the exPricer API

## Quick Start for Artists: Built-in Checkout System

If you're an artist looking to sell your digital work with dynamic pricing, follow these simple steps:

1. **Download and Upload**
   - Download all files to your web server
   - Make sure your server has PHP 7.4 or higher installed

2. **Configure Your Settings**
   - Make a copy of the sample configuration file `.env.example` and name the new copy `.env`
   - Fill in your settings in this `.env` file (this will include details about the digital file you are selling, the maximum number of copies you are selling, the minimum price, and technical details such as your Stripe account keys and email aaccount).
   - If you don't have a Stripe account go here: https://dashboard.stripe.com/register, and to learn about where to get your Stripe account keys, refer to: https://docs.stripe.com/keys

3. **Set Up Your Files**
   - Upload all exPricer project files to your web hosting account or personal web server. We recommend putting everything in a new folder, named as you like, or simply `buy`. (We decided to keep it simple, just use FTP/SFTP and copy over everything as is, in addition to your .env file of course!)
   - Upload your digital file to the `downloads` folder

4. **Test Your Setup**
   - Visit the web URL of your web hosting account or web server, remembering to add the folder name you created. For example, this could look something like https://hosting-company.com/myaccount/buy
   - Try a test purchase using Stripe's test card (4242 4242 4242 4242, any expiry date in the future, any 3-digit number as card validation code)
   - If it's a digital work, verify that the download link works and that the same link is sent by email
   - After your test purchase(s) you can reset the sales history by deleting the `sales_state.json` file located in the `data` folder

Checkout page screenshot

<img alt="exPricer-screenshot-CheckoutPage" src="https://github.com/user-attachments/assets/3d945ba9-aac8-4134-bda1-ddb9018b8ac9" width="300" />

## How It Works

1. **Pricing Logic (API + web checkout system)**
   - Price increases a little bit as fewer copies remain
   - The last copy is priced at a premium
   - A buyer choosing more exclusivity can make the price jump (as they are effectively paying you not to sell a certain number of copies that would be remaining)
   - For the same set of conditions (minimum price, number of copies remaining), a physical work will be priced at a small premium compared to the calculated price if it had been a digital work; this is to compensate the artist with the time to handle shipping or buyer pick-up (notwithstanding any actual shipping costs that the artist may want to pass along later to the buyer for actual shipping).

2. **Customer Experience (web checkout system)**
   - Customer visits your checkout page
   - They see different pricing options based on exclusivity
   - They select their preferred option and enter their email address
   - They complete payment through Stripe
   - If it is a digital item, they receive a download link on a payment success page, and via email (if configured); if it is a physical item, they receive a simple success message and an email requesting their full shipping address

## Important Notes

- Keep your `.env` file secure and never share it (it contains sensitive information!)
- The `downloads` and `data` directories are automatically protected from public web access
- Test the system thoroughly before going live

## Artist Support

If you need help setting up or using exPricer:
1. Make sure you've followed all the steps in the Quick Start for Arists section
2. Check that your `.env` file is properly configured
3. Verify that your web hosting account or personal web server has PHP 7.4 or higher installed
4. Ensure that your Stripe account is properly set up (e.g. not pending verification, not blocked, etc.)
5. If you're still having issues, please open an issue here on GitHub


***
***

## API Documentation for Developers

This section is for those interested in the exPricer dynamic pricing API, for example to integrate exPricer into another web checkout system or a custom web app.

## Installation

1. Clone the repository to your web server
2. Ensure PHP 7.4 or higher is installed
3. The API is now ready to use

## Endpoints

### Health Check endpoint
`GET /api/v1/health`

Checks the health status of the API.

#### Response
```json
{
    "status": "healthy",
    "version": "1.0.0",
    "timestamp": "2024-03-21T12:00:00+00:00",
    "service": "exPricer API"
}
```

### Calculate Prices endpoint
`POST /api/v1/calculate`

Calculates prices for different exclusivity levels based on the input parameters.

#### Request
```json
{
    "work_type": "physical",  // or "digital"
    "copies_sold": 0,         // number of copies already sold
    "max_copies": 100,        // maximum number of copies allowed
    "min_price": 100          // minimum acceptable price in $
}
```

#### Response
```json
{
    "exclusivity_levels": [
        {
            "remaining_copies": 100,
            "price": 120.00,
            "percentage_of_edition": 100.0,
            "is_last_copy": false,
            "is_current_level": true
        },
        // ... more exclusivity levels ...
    ],
    "current_state": {
        "total_copies": 100,
        "copies_sold": 0,
        "copies_remaining": 100,
        "work_type": "physical",
        "min_price": 100,
        "work_type_factor": 1.2,
        "current_market_price": 120
    }
}
```

#### Error Responses

##### Invalid Input (400)
```json
{
    "error": "Invalid input",
    "message": "Missing required field: work_type"
}
```

##### Method Not Allowed (405)
```json
{
    "error": "Method not allowed. Only POST requests are accepted."
}
```

##### Internal Server Error (500)
```json
{
    "error": "Internal server error",
    "message": "Error details..."
}
```

## API Call Sample Code

### cURL
```bash
# Health check
curl http://your-domain/api/v1/health

# Calculate prices
curl -X POST http://your-domain/api/v1/calculate \
  -H "Content-Type: application/json" \
  -d '{
    "work_type": "physical",
    "copies_sold": 0,
    "max_copies": 100,
    "min_price": 100
  }'
```

### PHP
```php
<?php

class ExPricerClient {
    private string $baseUrl;
    
    public function __construct(string $baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    public function checkHealth(): array {
        $response = $this->makeRequest('GET', '/api/v1/health');
        return json_decode($response, true);
    }
    
    public function calculatePrices(
        string $workType,
        int $copiesSold,
        int $maxCopies,
        float $minPrice
    ): array {
        $data = [
            'work_type' => $workType,
            'copies_sold' => $copiesSold,
            'max_copies' => $maxCopies,
            'min_price' => $minPrice
        ];
        
        $response = $this->makeRequest('POST', '/api/v1/calculate', $data);
        return json_decode($response, true);
    }
    
    private function makeRequest(string $method, string $endpoint, ?array $data = null): string {
        $ch = curl_init($this->baseUrl . $endpoint);
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ];
        
        if ($data !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new RuntimeException('API request failed: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            $error = json_decode($response, true);
            throw new RuntimeException(
                $error['message'] ?? 'API request failed with status ' . $httpCode
            );
        }
        
        return $response;
    }
}

// Usage example
try {
    $client = new ExPricerClient('http://your-domain');
    
    // Check API health
    $health = $client->checkHealth();
    echo "API Status: " . $health['status'] . "\n";
    
    // Calculate prices for a physical work
    $result = $client->calculatePrices(
        workType: 'physical',
        copiesSold: 0,
        maxCopies: 100,
        minPrice: 100
    );
    
    // Display current market price
    echo "Current market price: $" . $result['current_state']['current_market_price'] . "\n";
    
    // Display all exclusivity levels
    foreach ($result['exclusivity_levels'] as $level) {
        echo sprintf(
            "Remaining copies: %d, Price: $%.2f\n",
            $level['remaining_copies'],
            $level['price']
        );
    }
    
} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Python
```python
import requests

API_BASE_URL = "http://your-domain/api/v1"

# Health check
response = requests.get(f"{API_BASE_URL}/health")
print(response.json())

# Calculate prices
data = {
    "work_type": "physical",
    "copies_sold": 0,
    "max_copies": 100,
    "min_price": 100
}

response = requests.post(
    f"{API_BASE_URL}/calculate",
    json=data,
    headers={"Content-Type": "application/json"}
)

if response.status_code == 200:
    result = response.json()
    print("Current market price:", result["current_state"]["current_market_price"])
    for level in result["exclusivity_levels"]:
        print(f"Remaining copies: {level['remaining_copies']}, Price: ${level['price']}")
else:
    print("Error:", response.json())
```

### JavaScript/Node.js
```javascript
const fetch = require('node-fetch'); // or use axios

const API_BASE_URL = "http://your-domain/api/v1";

// Health check
async function checkHealth() {
    const response = await fetch(`${API_BASE_URL}/health`);
    const data = await response.json();
    console.log('API Status:', data.status);
}

// Calculate prices
async function calculatePrices() {
    const data = {
        work_type: "physical",
        copies_sold: 0,
        max_copies: 100,
        min_price: 100
    };

    try {
        const response = await fetch(`${API_BASE_URL}/calculate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();
        
        if (response.ok) {
            console.log('Current market price:', result.current_state.current_market_price);
            result.exclusivity_levels.forEach(level => {
                console.log(`Remaining copies: ${level.remaining_copies}, Price: $${level.price}`);
            });
        } else {
            console.error('Error:', result.error);
        }
    } catch (error) {
        console.error('Request failed:', error);
    }
}

// Run examples
checkHealth();
calculatePrices();
```

## Pricing Algorithm Details

The pricing system works as follows:

### Base Price Calculation
- Each work has a minimum price (`min_price`)
- Physical works have a 20% premium over digital works (work type factor)
- The current market price increases as more copies are sold (market factor)
- Market factor = 1 + (copies_sold / max_copies) * 0.5

### Market Factor Explained
The market factor creates a dynamic pricing model that reflects the increasing value of remaining copies:

1. **Starting Point**: When no copies are sold, the market factor is 1.0 (no increase)
2. **Linear Growth**: The factor increases proportionally as copies are sold
3. **Maximum Increase**: The factor can reach up to 1.5 (50% increase) when all but one copy is sold
4. **Formula**: Market Factor = 1 + (copies_sold / max_copies) * 0.5

Example for a 10-copy edition with $100 minimum price:
- First copy: $100 (market factor: 1.0)
- Fifth copy: $125 (market factor: 1.25)
- Last copy: $145 (market factor: 1.45)

This creates a natural price progression that:
- Rewards early buyers with lower prices
- Reflects increasing scarcity as the edition sells
- Maintains fair value throughout the edition
- Provides predictable price increases

### Exclusivity Options
- The system automatically calculates appropriate exclusivity levels based on the total number of copies made available for sale
- For small editions (≤10 copies), more granular options are offered to the purchaser
- For larger editions, percentage-based reductions in remaining supply (i.e. more optional paid-for exclusivity) are offered to the purchaser
- The purchaser always has the option to force their purchased copy to be the last copy sold.

### Price Calculation for Exclusivity
- The price for each exclusivity level is based on:
  1. The current market price for one copy
  2. Plus the value of all to-be-eliminated copies at the current market price

## Scenario Examples

### Physical Work, No Copies Sold Yet
- Current market price: $120 (base price × 1.2 for physical)
- Last copy price: $12,000 (current market price + value of 99 eliminated copies)

### Digital Work, Half of Maximum Allocation Already Sold
- Current market price: $125 (base price × market factor 1.25)
- Last copy price: $6,250 (current market price + value of 49 eliminated copies)

### Small Edition (10 copies), No Copies Sold Yet
- Item initial price: $100
- More granular options (1, 2, 3 copies)
- Last copy price: $1,000 (current market price + value of 9 eliminated copies)

## Contributing

Contributions are welcome! Please feel free to submit a pull request. When contributing, please ensure you maintain the attribution requirements of the license.
