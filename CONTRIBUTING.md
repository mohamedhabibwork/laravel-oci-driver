# Contributing to Laravel OCI Driver

Thank you for considering contributing to the Laravel OCI Driver! We welcome all contributions to improve this package.

## Code Standards
- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards.
- Use PHP 8.2+ features appropriately.
- Maintain strict typing and use type hints.
- Write clear, descriptive commit messages (use [Conventional Commits](https://www.conventionalcommits.org/)).
- Keep controllers thin and move business logic to services when possible.

## How to Contribute
1. **Fork the repository** and clone it locally.
2. **Create a new branch** for your feature or fix:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. **Write tests** for your changes (see Testing section below).
4. **Run all tests** and ensure they pass:
   ```bash
   composer test
   ```
5. **Format your code** using Laravel Pint:
   ```bash
   composer format
   ```
6. **Push your branch** and open a Pull Request on GitHub.

## Testing
- Write unit and feature tests for all new functionality.
- Use [Pest PHP](https://pestphp.com/) for tests when possible.
- Run `composer test` to execute the test suite.
- Maintain or improve code coverage.

## Submitting Issues
- Search [existing issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues) before opening a new one.
- Provide a clear description, steps to reproduce, and relevant environment details.
- Include error messages and configuration snippets if applicable.

## Pull Request Process
- Ensure your branch is up to date with `main`.
- All tests and static analysis must pass before merging.
- Add documentation for any new features or changes.

## Community
- Be respectful and inclusive in all interactions.
- For questions, use [GitHub Discussions](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions).

Thank you for helping make this package better! 