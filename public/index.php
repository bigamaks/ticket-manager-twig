<?php
// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once '../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

// Start session
$session = new Session();
$session->start();

// Create Twig environment
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../views');
$twig = new \Twig\Environment($loader, [
    'debug' => true,
    'cache' => false,
]);

// Add global variables to Twig
$twig->addGlobal('session', $session);

// User storage file
$usersFile = __DIR__ . '/../data/users.json';

// Load users from JSON file or initialize with demo user
if (file_exists($usersFile)) {
    $users = json_decode(file_get_contents($usersFile), true) ?? [];
} else {
    $users = [
        'john@example.com' => [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => password_hash('password123', PASSWORD_DEFAULT)
        ]
    ];
    // Create directory if it doesn't exist
    $dataDir = dirname($usersFile);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

// Function to save users to file
function saveUsers($users, $usersFile) {
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

// Simple routing
$request = Request::createFromGlobals();
$path = $request->getPathInfo();

$routes = [
    '/' => ['template' => 'pages/landing.html.twig', 'auth' => false],
    '/login' => ['template' => 'pages/login.html.twig', 'auth' => false],
    '/signup' => ['template' => 'pages/signup.html.twig', 'auth' => false],
    '/dashboard' => ['template' => 'pages/dashboard.html.twig', 'auth' => true],
    '/tickets' => ['template' => 'pages/tickets.html.twig', 'auth' => true],
    '/logout' => ['template' => null, 'auth' => true],
];

// Handle logout first
if ($path === '/logout') {
    $session->clear();
    setcookie('ticketapp_session', '', time() - 3600, "/");
    $response = new Response('', 302, ['Location' => '/']);
    $response->send();
    exit;
}

// Handle login form submission
if ($path === '/login' && $request->getMethod() === 'POST') {
    $email = $request->request->get('email');
    $password = $request->request->get('password');
    
    $errors = [];
    
    // Validation
    if (empty($email)) {
        $errors[] = 'Email is required';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (empty($errors)) {
        // Check if user exists and password is correct
        if (isset($users[$email]) && password_verify($password, $users[$email]['password'])) {
            // Login successful
            $user = $users[$email];
            unset($user['password']); // Don't store password in session
            
            $session->set('user', $user);
            
            // Set session token in cookie for localStorage simulation
            $sessionToken = bin2hex(random_bytes(32));
            setcookie('ticketapp_session', $sessionToken, time() + (86400 * 30), "/");
            
            $response = new Response('', 302, ['Location' => '/dashboard']);
            $response->send();
            exit;
        } else {
            $errors[] = 'Invalid email or password';
        }
    }
    
    // If we get here, there were errors - render login template with errors
    $data = ['errors' => $errors, 'email' => $email];
    $template = $twig->load($routes[$path]['template']);
    $response = new Response($template->render($data));
    $response->send();
    exit;
}

// Handle signup form submission
if ($path === '/signup' && $request->getMethod() === 'POST') {
    $name = $request->request->get('name');
    $email = $request->request->get('email');
    $password = $request->request->get('password');
    $confirmPassword = $request->request->get('confirm_password');
    
    $errors = [];
    
    // Validation
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    } elseif (isset($users[$email])) {
        $errors[] = 'Email already exists';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    if (empty($confirmPassword)) {
        $errors[] = 'Please confirm your password';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }
    
    if (empty($errors)) {
        // Create new user
        $newUser = [
            'id' => count($users) + 1,
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT)
        ];
        
        // Add to users array and save to file
        $users[$email] = $newUser;
        saveUsers($users, $usersFile);
        
        // Auto-login after signup
        $user = $newUser;
        unset($user['password']);
        
        $session->set('user', $user);
        
        // Set session token
        $sessionToken = bin2hex(random_bytes(32));
        setcookie('ticketapp_session', $sessionToken, time() + (86400 * 30), "/");
        
        $response = new Response('', 302, ['Location' => '/dashboard']);
        $response->send();
        exit;
    }
    
    // If we get here, there were errors - render signup template with errors
    $data = [
        'errors' => $errors,
        'name' => $name,
        'email' => $email
    ];
    $template = $twig->load($routes[$path]['template']);
    $response = new Response($template->render($data));
    $response->send();
    exit;
}

// Check if route exists
if (!isset($routes[$path])) {
    $response = new Response('Page not found', 404);
    $response->send();
    exit;
}

// Check authentication for protected routes
$route = $routes[$path];
if ($route['auth'] && !$session->get('user')) {
    $response = new Response('', 302, ['Location' => '/login']);
    $response->send();
    exit;
}

// Start with empty tickets array - no mock data
$tickets = [];

// Get user-specific localStorage key
$userEmail = $session->get('user')['email'] ?? 'default';
$localStorageKey = 'ticketapp_tickets_' . md5($userEmail);

// Check if we have localStorage data in cookie (for demo purposes)
$localStorageTickets = [];
if (isset($_COOKIE[$localStorageKey])) {
    $localStorageTickets = json_decode($_COOKIE[$localStorageKey], true) ?? [];
} elseif (isset($_COOKIE['ticketapp_tickets'])) {
    // Fallback to generic key for backward compatibility
    $localStorageTickets = json_decode($_COOKIE['ticketapp_tickets'], true) ?? [];
}

// Merge PHP empty array with localStorage data (if any)
$allTickets = array_merge($tickets, $localStorageTickets);

// Remove duplicates based on ticket ID
$uniqueTickets = [];
$usedIds = [];
foreach ($allTickets as $ticket) {
    if (!in_array($ticket['id'], $usedIds)) {
        $uniqueTickets[] = $ticket;
        $usedIds[] = $ticket['id'];
    }
}

// Prepare data for template
$data = [
    'user' => $session->get('user'),
    'tickets' => $uniqueTickets,
    'current_path' => $path,
    'app' => ['request' => $request]
];

// Handle ticket form submissions
if ($path === '/tickets' && $request->getMethod() === 'POST') {
    $action = $request->request->get('action');
    
    if ($action === 'create_ticket') {
        $title = $request->request->get('title');
        $description = $request->request->get('description');
        $status = $request->request->get('status');
        $priority = $request->request->get('priority');
        
        $errors = [];
        
        // Validation
        if (empty($title)) {
            $errors[] = 'Title is required';
        }
        
        if (empty($status)) {
            $errors[] = 'Status is required';
        } elseif (!in_array($status, ['open', 'in_progress', 'closed'])) {
            $errors[] = 'Invalid status value';
        }
        
        if (empty($errors)) {
            // Create new ticket
            $newTicket = [
                'id' => uniqid(),
                'title' => $title,
                'description' => $description ?? '',
                'status' => $status,
                'priority' => $priority ?? 'medium',
                'createdAt' => date('Y-m-d')
            ];
            
            // Add to tickets array
            $uniqueTickets[] = $newTicket;
            
            // Save to cookie (simulating localStorage)
            setcookie($localStorageKey, json_encode($uniqueTickets), time() + (86400 * 30), "/");
            
            // Redirect to prevent form resubmission
            $response = new Response('', 302, ['Location' => '/tickets?success=1']);
            $response->send();
            exit;
        } else {
            // If errors, show tickets page with errors
            $data['errors'] = $errors;
            $template = $twig->load($routes[$path]['template']);
            $response = new Response($template->render($data));
            $response->send();
            exit;
        }
    }
    
    if ($action === 'update_ticket') {
        $ticketId = $request->request->get('id');
        $title = $request->request->get('title');
        $description = $request->request->get('description');
        $status = $request->request->get('status');
        $priority = $request->request->get('priority');
        
        $errors = [];
        
        // Validation
        if (empty($title)) {
            $errors[] = 'Title is required';
        }
        
        if (empty($status)) {
            $errors[] = 'Status is required';
        } elseif (!in_array($status, ['open', 'in_progress', 'closed'])) {
            $errors[] = 'Invalid status value';
        }
        
        if (empty($errors)) {
            // Find and update ticket
            foreach ($uniqueTickets as &$ticket) {
                if ($ticket['id'] == $ticketId) {
                    $ticket['title'] = $title;
                    $ticket['description'] = $description ?? '';
                    $ticket['status'] = $status;
                    $ticket['priority'] = $priority ?? 'medium';
                    break;
                }
            }
            
            // Save updated tickets
            setcookie($localStorageKey, json_encode($uniqueTickets), time() + (86400 * 30), "/");
            
            // Redirect to prevent form resubmission
            $response = new Response('', 302, ['Location' => '/tickets?success=1']);
            $response->send();
            exit;
        } else {
            // If errors, show tickets page with errors
            $data['errors'] = $errors;
            $template = $twig->load($routes[$path]['template']);
            $response = new Response($template->render($data));
            $response->send();
            exit;
        }
    }
    
    if ($action === 'delete_ticket') {
        $ticketId = $request->request->get('id');
        
        // Remove ticket from array
        $uniqueTickets = array_filter($uniqueTickets, function($ticket) use ($ticketId) {
            return $ticket['id'] != $ticketId;
        });
        
        // Save updated tickets
        setcookie($localStorageKey, json_encode(array_values($uniqueTickets)), time() + (86400 * 30), "/");
        
        // Redirect back to tickets page
        $response = new Response('', 302, ['Location' => '/tickets']);
        $response->send();
        exit;
    }
}

// Render template
try {
    $template = $twig->load($route['template']);
    $response = new Response($template->render($data));
    $response->send();
} catch (\Twig\Error\LoaderError $e) {
    // Debug template loading errors
    http_response_code(500);
    echo "Template Error: " . $e->getMessage() . "<br><br>";
    echo "Template path: " . $route['template'] . "<br>";
    echo "Available templates in views/pages/: <br>";
    
    $pagesPath = __DIR__ . '/../views/pages/';
    if (is_dir($pagesPath)) {
        $files = scandir($pagesPath);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                echo "- " . $file . "<br>";
            }
        }
    } else {
        echo "Pages directory not found: " . $pagesPath;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "Application Error: " . $e->getMessage();
}