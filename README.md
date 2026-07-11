# Devcore PHP Backend

This is a PHP conversion of the Devcore backend, originally written in Node.js.

## Features
- Fully compatible with the original API structure and responses.
- Uses Supabase as the database.
- JWT Authentication.
- Clean and modular structure.

## Requirements
- PHP 8.0+
- Composer
- Apache with `mod_rewrite` enabled (for `.htaccess`)

## Setup
1. Clone the repository.
2. Run `composer install`.
3. Create a `.env` file based on the original project.
4. Set up your web server to point to the `public` directory.

## Structure
- `config/`: Configuration files (Supabase, etc.)
- `controllers/`: API controllers logic.
- `middleware/`: Authentication middleware.
- `public/`: Entry point and public assets.
- `routes/`: Routing logic (handled in `public/index.php` for simplicity).
