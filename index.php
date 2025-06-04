<?php
/**
 * exPricer Checkout Page
 * 
 * This is a sample checkout page that demonstrates how to use exPricer
 * with Stripe payments for digital products.
 */

// Checkout page for exPricer
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load configuration
require_once __DIR__ . '/src/autoload.php';
use exPricer\Core\Config;
use exPricer\Core\PriceCalculator;
use exPricer\Core\SalesTracker;

try {
    Config::load();
} catch (Exception $e) {
    error_log("Configuration error: " . $e->getMessage());
    die("Configuration error. Please contact support.");
}

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
}

// Simple Stripe API call with error handling
function createStripeSession($priceData) {
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, Config::get('STRIPE_SECRET_KEY') . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($priceData));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Stripe API Error: $error");
        throw new \RuntimeException('Failed to connect to Stripe');
    }
    
    if ($httpCode !== 200) {
        error_log("Stripe API Error: HTTP $httpCode - $response");
        throw new \RuntimeException('Stripe API error: ' . $response);
    }
    
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Stripe API Error: Invalid JSON response - $response");
        throw new \RuntimeException('Invalid response from Stripe');
    }
    
    return $result;
}

// Handle form submission
$error = null;
$sessionUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate selected level
        if (!isset($_POST['selected_level'])) {
            throw new \RuntimeException('No exclusivity level selected');
        }
        
        $selectedLevel = json_decode($_POST['selected_level'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid exclusivity level data');
        }
        
        if (!isset($selectedLevel['remaining_copies']) || !isset($selectedLevel['price'])) {
            throw new \RuntimeException('Invalid exclusivity level format');
        }
        
        // Create Stripe checkout session
        $sessionData = [
            'payment_method_types[]' => 'card',
            'line_items[][price_data][currency]' => 'usd',
            'line_items[][price_data][product_data][name]' => Config::get('PRODUCT_NAME'),
            'line_items[][price_data][product_data][description]' => sprintf(
                '%s (Exclusivity: %d copies remaining)',
                Config::get('PRODUCT_DESCRIPTION'),
                $selectedLevel['remaining_copies']
            ),
            'line_items[][price_data][unit_amount]' => $selectedLevel['price'] * 100, // Convert to cents
            'line_items[][quantity]' => 1,
            'mode' => 'payment',
            'success_url' => rtrim(str_replace('/public', '', Config::get('APP_URL')), '/') . '/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(str_replace('/public', '', Config::get('APP_URL')), '/'),
            'metadata[selected_level]' => $_POST['selected_level']
        ];
        
        $session = createStripeSession($sessionData);
        $sessionUrl = $session['url'];
        
    } catch (\Exception $e) {
        error_log("Checkout Error: " . $e->getMessage());
        $error = $e->getMessage();
    }
}

// Get current prices and sales info
try {
    // Get sales tracker info first
    $salesTracker = new SalesTracker();
    $totalCopies = Config::get('MAX_COPIES');
    $copiesSold = $salesTracker->getCopiesSold();
    $copiesAvailable = $totalCopies - $copiesSold;
    
    // Calculate prices using actual copies sold
    $calculator = new PriceCalculator();
    $prices = $calculator->calculatePrices(
        Config::get('WORK_TYPE'),
        $copiesSold, // Use actual copies sold
        Config::get('MAX_COPIES'),
        Config::get('MIN_PRICE')
    );
    
} catch (\Exception $e) {
    error_log("Price Calculation Error: " . $e->getMessage());
    die('Failed to calculate prices: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?php echo htmlspecialchars(Config::get('PRODUCT_NAME')); ?></title>
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1, h2, h3 {
            color: #333;
            margin-bottom: 20px;
        }
        .product-info {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
            display: flex;
            gap: 20px;
        }
        .product-info .text-content {
            flex: 1;
        }
        .product-info h2 {
            margin-top: 0;
            color: #007bff;
        }
        .product-info .image-container {
            flex: 0 0 200px;
            width: 200px;
            height: 200px;
            position: relative;
            overflow: hidden;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .product-info .image-container img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }
        .exclusivity-levels {
            margin: 30px 0;
        }
        .level {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .level:hover {
            border-color: #007bff;
            background: #f8f9ff;
        }
        .level input[type="radio"] {
            width: 20px;
            height: 20px;
            margin: 0;
        }
        .level .description {
            flex-grow: 1;
        }
        .level .title {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        .level .details {
            color: #666;
            font-size: 0.9em;
        }
        .level .price {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            white-space: nowrap;
        }
        .checkout-button {
            background: #007bff;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 5px;
            font-size: 18px;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s ease;
            margin-top: 20px;
        }
        .checkout-button:hover {
            background: #0056b3;
        }
        .checkout-button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .error {
            color: #dc3545;
            background: #f8d7da;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .current-state {
            margin-bottom: 30px;
            padding: 15px;
            background: #e9ecef;
            border-radius: 5px;
        }
        .current-state p {
            margin: 5px 0;
            color: #666;
        }
        .sales-info {
            background: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 30px;
            text-align: center;
        }
        .sales-info p {
            margin: 5px 0;
            color: #666;
        }
        .sales-info .highlight {
            color: #007bff;
            font-weight: bold;
        }
        @media (max-width: 600px) {
            .container {
                padding: 20px;
            }
            .level {
                padding: 15px;
            }
            .level label {
                font-size: 16px;
            }
            .level .price {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Purchase options</h1>
        
        <?php if ($error): ?>
            <div class="error">
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($sessionUrl): ?>
            <script>
                window.location.href = <?php echo json_encode($sessionUrl); ?>;
            </script>
        <?php else: ?>
            <form method="post" id="checkout-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="product-info">
                    <div class="text-content">
                        <h2><?php echo htmlspecialchars(Config::get('PRODUCT_NAME')); ?></h2>
                        <p><?php echo strip_tags(Config::get('PRODUCT_DESCRIPTION'), '<p><br><strong><em><u><h1><h2><h3><h4><h5><h6><ul><ol><li><blockquote><code><pre><a>'); ?></p>
                    </div>
                    <?php 
                    $productImage = Config::get('PRODUCT_IMAGE');
                    if (!empty($productImage) && filter_var($productImage, FILTER_VALIDATE_URL)): 
                    ?>
                        <div class="image-container">
                            <img src="<?php echo htmlspecialchars($productImage); ?>" alt="<?php echo htmlspecialchars(Config::get('PRODUCT_NAME')); ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="sales-info">
                    <p>Total copies available for sale: <span class="highlight"><?php echo $totalCopies; ?></span></p>
                    <p>Copies sold so far: <span class="highlight"><?php echo $copiesSold; ?></span></p>
                    <p>Copies still available: <span class="highlight"><?php echo $copiesAvailable; ?></span></p>
                </div>
                
                <div class="exclusivity-levels">
                    <h3>Choose Exclusivity Level</h3>
                    <?php if ($copiesAvailable <= 0): ?>
                        <div class="level" style="background-color: #f8d7da; border-color: #dc3545;">
                            <div class="description">
                                <div class="title">Sold out</div>
                                <div class="details">All copies have been sold. Thank you for your interest!</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($prices['exclusivity_levels'] as $index => $level): ?>
                            <div class="level">
                                <?php 
                                // Adjust the remaining copies to reflect what will be left after purchase
                                $level['remaining_copies'] = $level['remaining_copies'] - 1;
                                ?>
                                <input type="radio" name="selected_level" value="<?php echo htmlspecialchars(json_encode($level)); ?>" required>
                                <div class="description">
                                    <?php if ($level['remaining_copies'] === 0): ?>
                                        <div class="title">Last copy option</div>
                                        <div class="details">This purchase will be the last copy sold. The artist will not sell any more copies after this.</div>
                                    <?php elseif ($index === 0): ?>
                                        <div class="title">Limited edition</div>
                                        <div class="details"><?php echo $level['remaining_copies']; ?> copies will be available for sale after this purchase.</div>
                                    <?php else: ?>
                                        <div class="title">Pay for more exclusivity</div>
                                        <div class="details"><?php echo $level['remaining_copies']; ?> copies will be available for sale after this purchase.</div>
                                    <?php endif; ?>
                                </div>
                                <span class="price">$<?php echo number_format($level['price'], 2); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <?php if ($copiesAvailable > 0): ?>
                    <button type="submit" class="checkout-button">Proceed to payment</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</body>
</html> 