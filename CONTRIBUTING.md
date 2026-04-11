# Contributing to Hibla

Thank you for showing interest in contributing to this Hibla library! Contributions are essential for building a robust async ecosystem for the PHP community.

This library is designed to be a reliable foundation for high-performance applications. To achieve this, it maintains rigorous standards for code quality and developer experience.

## Development Workflow

To ensure consistency across the ecosystem, this repository requires the following workflow:

1. **Fork and Branch**: Fork the repository and create a feature branch from `main`.
2. **Dependencies**: Install development tools using `composer install`.
3. **Coding Standards**: This project follows strict PSR-12 standards. Use Laravel Pint to format code: `./vendor/bin/pint`.
4. **Static Analysis**: Code must be predictable and type-safe. It must pass PHPStan at the maximum level: `./vendor/bin/phpstan analyse`.
5. **Testing**: This project uses Pest. Ensure the test suite passes completely: `./vendor/bin/pest`.
6. **Strict Typing**: Every PHP file must begin with `declare(strict_types=1);`.

## Technical Philosophy

Contributions should align with the core Hibla values:

- **Developer Experience (DX)**: APIs should be intuitive and "just work." If a feature is hard to explain, it probably needs a simpler design.
- **Predictability**: Code should behave consistently across different environments. Execution order and timing must be reliable.
- **Resource Integrity**: Every operation must be mindful of the system resources it consumes. Always provide a way to clean up or cancel long-running tasks.
- **Minimalism**: This library aims to solve specific problems with as few dependencies as possible.

## Pull Request Process

1. **Start with an Issue**: Before writing code, please open an issue to discuss the bug or the proposed feature.
2. **Tests are Required**: Every Pull Request must include automated tests that cover the new logic or prevent the bug from recurring.
3. **Documentation**: If a change affects the public API, the `README.md` must be updated to reflect the new behavior.

---

The Hibla ecosystem thanks you for your time and effort!