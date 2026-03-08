# GitHub Copilot Instructions for Decker WordPress Plugin

This document provides specific instructions for GitHub Copilot when working on the Decker WordPress plugin.

## Project Overview

Decker is a WordPress plugin for task management with a Kanban board interface. It's developed by Área de Tecnología Educativa (ATE) and follows WordPress coding standards.

## Coding Standards

### WordPress Coding Standards
- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) for all PHP, HTML, CSS, and JavaScript code
- Use 4 spaces for indentation (not tabs)
- Limit lines to 80 characters where possible
- Use `snake_case` for functions, methods, and variables
- Use `CamelCase` for class names
- Name files with lowercase letters and hyphens (e.g., `class-decker-admin.php`)

### PHP Specific
- All PHP functions and methods must have English PHPDoc comments
- Use proper escaping (`esc_html()`, `esc_attr()`, `esc_url()`) for all output
- Sanitize all user inputs using WordPress functions (`sanitize_text_field()`, etc.)
- Use WordPress nonces for form submissions and AJAX requests
- Prefer WordPress APIs over raw PHP functions when available

### Code Structure
- Keep the main plugin file (`decker.php`) minimal
- Place each class in its own file with pattern `class-pluginname-component.php`
- Admin functionality goes in the `admin/` directory
- Public-facing functionality goes in the `public/` directory
- Shared utilities and custom post types go in the `includes/` directory
- Tests go in the `tests/` directory

## Language Requirements

### Source Code
- Write all source code (identifiers, comments, docblocks) in **English**
- Use clear, descriptive names that self-document the code

### User-Facing Content
- All user-facing strings must be in **Spanish**
- Use WordPress translation functions: `__()`, `_e()`, `_n()`, `_x()`
- Text domain is `decker`
- **Always add Spanish translations** for every new translatable string to `languages/decker-es_ES.po` in the same commit that introduces the string
- Always verify no untranslated Spanish strings remain using `make check-untranslated`

## Development Workflow

### Test-Driven Development (TDD)
- Write tests BEFORE implementing features when possible
- Use PHPUnit for PHP tests, Jest for JavaScript tests
- Tests live under `/tests/` directory
- Use factory classes to create test fixtures
- Run tests with `make test`

### Code Quality
- Run `make lint` to check PHP code style
- Run `make fix` to auto-fix code style issues
- Ensure all linting passes before committing

### Environment
- Develop within `@wordpress/env` environment
- Start local environment with `make up`
- Access at http://localhost:8888 (admin/password)

## Security

### Input/Output Handling
- **Always** validate user inputs
- **Always** sanitize data before storing
- **Always** escape output before displaying
- Use WordPress nonces for all forms and AJAX
- Follow principle of least privilege

### Best Practices
- Avoid SQL injection by using `$wpdb->prepare()`
- Prevent XSS attacks with proper escaping
- Check user capabilities before performing privileged operations
- Validate file uploads and restrict file types

## Frontend Technologies

- Use **Bootstrap 5** for UI components
- Use **jQuery** for JavaScript interactions
- Keep frontend assets minimal
- Enqueue assets properly via `wp_enqueue_script()` and `wp_enqueue_style()`
- Use minified versions in production

## Common Patterns

### Adding a New Feature
1. Write failing test(s) first (TDD)
2. Implement minimal code to pass tests
3. Add Spanish translations for every new `__()`, `_e()`, `_n()`, `_x()` call to `languages/decker-es_ES.po`
4. Run `make lint` and `make fix`
5. Run `make test` to verify all tests pass
6. Run `make check-untranslated` to verify translations

### Creating a New Class
```php
<?php
/**
 * Brief description of class purpose.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

/**
 * Class description.
 *
 * Longer description if needed.
 *
 * @since      1.0.0
 */
class Decker_Component {
    // Implementation
}
```

### Adding Translation
```php
// For simple strings
__( 'Spanish text here', 'decker' )

// For output
esc_html_e( 'Spanish text here', 'decker' )

// For plurals
_n( 'singular', 'plural', $count, 'decker' )
```

## Documentation

- Update PHPDoc blocks for all modified functions/classes
- Update `README.md` if user-facing features change
- Update `readme.txt` for WordPress.org compatibility
- Keep `CONVENTIONS.md` and `AGENTS.md` synchronized

## Additional Resources

- Project conventions: See `CONVENTIONS.md`
- Agent-specific guidelines: See `AGENTS.md`
- WordPress Coding Standards: https://developer.wordpress.org/coding-standards/
- WordPress Plugin Handbook: https://developer.wordpress.org/plugins/

## Quick Reference

### Makefile Commands
- `make up` - Start WordPress environment
- `make down` - Stop WordPress environment  
- `make test` - Run all tests
- `make lint` - Check code style
- `make fix` - Auto-fix code style
- `make check-untranslated` - Check for untranslated Spanish strings

### Key Principles
1. **WordPress First**: Always use WordPress APIs
2. **Security First**: Validate input, sanitize storage, escape output
3. **Test First**: Write tests before implementation (TDD)
4. **Spanish UI**: All user-facing text in Spanish
5. **English Code**: All code and comments in English
6. **Minimal Changes**: Make smallest possible changes to achieve goals
