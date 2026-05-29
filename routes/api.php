<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Auth & Registration
require __DIR__ . '/auth.php';

// Products & Inventory
require __DIR__ . '/products.php';

// Orders & Geo-tracking
require __DIR__ . '/orders.php';

// Users, Profiles & Favorites
require __DIR__ . '/users.php';

// Businesses, Owners & Affiliations
require __DIR__ . '/business.php';

// Payments & Webhooks
require __DIR__ . '/payments.php';

// Chat Conversations
require __DIR__ . '/chats.php';

// Domiciliaries / Delivery Staff
require __DIR__ . '/domiciliaries.php';

// Ratings & Reviews
require __DIR__ . '/reviews.php';
