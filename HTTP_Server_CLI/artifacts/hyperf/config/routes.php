<?php
/**
 * Hyperf benchmark routes.
 *
 * Same route set as all benchmark competitors:
 * 10 static + 10 dynamic + 6 nested + 3 middleware + catch-all 404.
 *
 * All routes return plain text responses matching the exact format
 * of other competitors. No views, no JSON — just text/plain.
 */

declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;

// --- Static routes (10) ---
$statics = [
   '/'        => 'Home',
   '/about'   => 'About',
   '/contact' => 'Contact',
   '/blog'    => 'Blog',
   '/pricing' => 'Pricing',
   '/docs'    => 'Docs',
   '/faq'     => 'FAQ',
   '/terms'   => 'Terms',
   '/privacy' => 'Privacy',
   '/status'  => 'Status',
];

foreach ($statics as $path => $body) {
   Router::get($path, function () use ($body) {
      return $body;
   });
}

// --- Dynamic routes (10) ---
Router::get('/user/{id}', function (string $id) {
   return 'User: ' . $id;
});
Router::get('/post/{slug}', function (string $slug) {
   return 'Post: ' . $slug;
});
Router::get('/api/v1/{resource}', function (string $resource) {
   return 'API: ' . $resource;
});
Router::get('/category/{name}', function (string $name) {
   return 'Category: ' . $name;
});
Router::get('/tag/{label}', function (string $label) {
   return 'Tag: ' . $label;
});
Router::get('/product/{sku}', function (string $sku) {
   return 'Product: ' . $sku;
});
Router::get('/order/{code}', function (string $code) {
   return 'Order: ' . $code;
});
Router::get('/invoice/{number}', function (string $number) {
   return 'Invoice: ' . $number;
});
Router::get('/review/{rid}', function (string $rid) {
   return 'Review: ' . $rid;
});
Router::get('/comment/{cid}', function (string $cid) {
   return 'Comment: ' . $cid;
});

// --- Nested routes (6) — 2 groups ---
Router::get('/admin/dashboard', function () {
   return 'Admin Dashboard';
});
Router::get('/admin/settings', function () {
   return 'Admin Settings';
});
Router::get('/admin/users', function () {
   return 'Admin Users';
});
Router::get('/account/profile', function () {
   return 'Account Profile';
});
Router::get('/account/billing', function () {
   return 'Account Billing';
});
Router::get('/account/security', function () {
   return 'Account Security';
});

// --- Middleware routes (3) — no actual middleware for non-Bootgly competitors ---
Router::get('/protected/dashboard', function () {
   return 'Protected Dashboard';
});
Router::get('/protected/settings', function () {
   return 'Protected Settings';
});
Router::get('/protected/profile', function () {
   return 'Protected Profile';
});
