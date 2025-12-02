# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**SuluPHPCRMigrationBundle** is a Symfony bundle that migrates Sulu CMS content from PHPCR (v2.6) to the new SuluContentBundle database structure (v3.0). It handles pages, snippets, and articles with full localization support.

## Development Commands

Use Symfony composer script commands (defined in composer.json):

```bash
# Testing & Quality
composer test           # Run all tests (PHPUnit)
composer phpunit        # Run tests only
composer lint           # Run all linters (PHPStan, PHP-CS-Fixer, Rector, Deptrac)
composer fix            # Auto-fix code style issues

# Individual Quality Tools
composer phpstan        # Static analysis (level: max)
composer php-cs         # Check code style (dry-run)
composer php-cs-fix     # Fix code style
composer rector         # Apply automated refactoring
composer lint-rector    # Check rector rules (dry-run)
composer deptrac        # Check architectural dependencies
```

**Runtime Commands**:
```bash
php bin/adminconsole phpcr:migrations:migrate           # Prepare PHPCR structure
php bin/adminconsole sulu:phpcr-migration:migrate       # Run full migration
php bin/adminconsole sulu:phpcr-migration:migrate page  # Migrate specific type (page|snippet|article|custom_url|snippet_area)
```

## Architecture Overview

This bundle follows **Clean Architecture** principles enforced by Deptrac:

```
PhpcrMigration/
├── UserInterface/       → Entry points (Console commands)
├── Application/         → Business logic & use cases
│   ├── Parser/         → Parse PHPCR nodes to arrays
│   ├── Persister/      → Persist to new storage
│   ├── Session/        → PHPCR session management
│   ├── Repository/     → Data access abstraction
│   └── Exception/      → Domain exceptions
└── Infrastructure/      → External implementations
```

**Dependency Rules** (from deptrac.yaml):
- UserInterface → Application
- Infrastructure → Application
- Application → Domain (when Domain layer exists)
- No reverse dependencies allowed

### Key Design Patterns

1. **Chain of Responsibility** (Parsers):
   - `ChainNodeParser` orchestrates multiple parsers
   - Each parser (`PageNodeParser`, `ArticleNodeParser`, etc.) handles specific logic
   - Parsers registered via Symfony service tags: `sulu_phpcr_migration.node_parser`

2. **Strategy Pattern** (Persisters):
   - `PersisterPool` selects appropriate persister by content type
   - Each persister (`PagePersister`, `SnippetPersister`, `ArticlePersister`) implements type-specific logic
   - Persisters registered via service tags: `sulu_phpcr_migration.persister`

3. **Repository Pattern**:
   - `EntityRepository` provides abstraction over Doctrine with entity caching
   - Implements `EntityRepositoryInterface` for testability

### Service Configuration

- **Format**: XML (not YAML) - see `Resources/config/*.xml`
- **Service Prefix**: `sulu_phpcr_migration.*`
- **Extension Pattern**: Tagged iterator for parsers and persisters
- **Dependency Injection**: Constructor injection exclusively

## Code Conventions

### PHP Standards

- **PHP Version**: 8.1+ (supports up to 8.4)
- **Strict Types**: Required in all files (`declare(strict_types=1);`)
- **Type Hints**: Full type hints on all parameters and return types
- **Array Shapes**: Use PHPStan docblocks for complex array structures
- **Modern PHP**: Use readonly properties, constructor property promotion, attributes where appropriate

### Quality Requirements

- **PHPStan Level**: max (strictest level)
- **Code Style**: @Symfony standard via PHP-CS-Fixer
- **License Header**: MIT license required in all files
- **Architecture**: Deptrac validates layer dependencies - do not violate defined rules

### PHPCR-Specific Patterns

**Property Parsing**:
- Localized properties use `i18n-{locale}` prefix (e.g., `i18n-en-title`)
- Block properties require length trimming via `PropertyParser::trimBlocksToLengths()`
- Nested blocks (block within block) require special handling
- Image maps use property path resolution
- Excerpt properties use `excerpt-` prefix (e.g., `i18n-en-excerpt-title`)
- Segments stored as separate PHPCR properties per webspace (e.g., `i18n-en-excerpt-segments-sulu_io`, `i18n-en-excerpt-segments-blog`) and reconstructed into a map structure by the parser

**Block ID Migration**:
- Migrated blocks automatically receive unique IDs during persistence
- Block IDs are 8-character hexadecimal strings (e.g., `a1b2c3d4`)
- Generated using xxHash 32-bit algorithm with microsecond-precision timestamps
- IDs added recursively to all block structures in templateData
- Preserves existing IDs if already present (idempotent operation)
- Block detection: numerically indexed arrays where elements have `'type'` key
- Handles nested blocks (blocks within blocks) automatically

**SEO Data Migration** (Sulu 3.0):
- **JSON Column**: `seoData` stores title, description, keywords, canonicalUrl
- **Separate Columns**: `seoNoIndex`, `seoNoFollow`, `seoHideInSitemap` (booleans)
- **Format**: `{"title": "...", "description": "...", "keywords": "...", "canonicalUrl": "..."}`
- **Note**: Snippets do not have SEO data

**Excerpt Tab Migration** (Sulu 3.0):
- **JSON Column**: `excerptData` stores title, description, more, image, icon
- **Format**: `{"title": "...", "description": "...", "more": "...", "image": {"id": 123}, "icon": {"id": 456}}`
- **Separate Column**: `excerptSegment` (VARCHAR)
- **Categories & Tags**: Migrated as many-to-many relations via junction tables (unchanged)
- **Audience Targeting Groups**: Migrated as many-to-many relations (unchanged)
- **Segments**:
  - PHPCR storage: Separate properties per webspace (`excerpt-segments-{webspace}`)
  - Parser reconstruction: `["webspace_key" => "segment_value"]` map
  - Sulu 3.0 target: Single VARCHAR column storing one segment value
  - Transformation logic:
    - Pages: Extract segment value for the page's webspace
    - Articles/Snippets: Use first segment value from the map

**CustomUrl Migration**:
- **PHPCR Structure**: CustomUrl documents with embedded routes as child nodes
- **Sulu 3.0 Structure**: Two separate tables (`cu_custom_url` and `cu_custom_url_route`)
- **Key Changes**:
  - CustomUrl now has explicit `webspace` field (extracted from PHPCR path `/cmf/<webspace>/custom-urls/`)
  - Routes stored in separate table with foreign key relationship
  - Self-referencing `target_route_uuid` enables history route chains for 301 redirects
  - Published state determines visibility (no draft/live stages like pages)
- **Properties Migrated**:
  - Main entity: uuid, title, published, baseDomain, webspace, domainParts (JSON)
  - Target reference: targetDocument (page UUID), targetLocale
  - SEO properties: canonical, redirect, noFollow, noIndex
  - Routes: uuid, path, history flag (in separate table)
- **Route Handling**: PHPCR child nodes (`routes/*`) migrated to `cu_custom_url_route` records

**Snippet Area Migration**:
- **PHPCR Structure**: Properties on webspace nodes (`/cmf/{webspace}`)
- **Property Pattern**: `settings:snippets-{areaKey}` with value as snippet node reference
- **Sulu 3.0 Structure**: Single table (`sn_snippet_area`)
- **Key Differences**:
  - PHPCR: Properties scattered across webspace nodes
  - Sulu 3.0: Dedicated table with webspaceKey + areaKey + snippet FK
- **Migration Strategy**:
  - Query webspace nodes under `/cmf/*`
  - Extract all `settings:snippets-*` properties
  - Parse property name to get areaKey (e.g., `settings:snippets-footer` → `footer`)
  - Resolve snippet node reference to UUID
  - Create record: (uuid, webspaceKey, areaKey, idSnippet)
- **Special Handling**: Only migrated from default session (not live session)

**Query Strategy**:
- Use SQL2 queries to fetch nodes by mixin type
- Sort by path depth first, then `sulu:order` to ensure parent-child processing order
- Example: `SELECT * FROM [sulu:page] ORDER BY depth, [sulu:order]`

**Session Management**:
- Dual workspace support: `default` and `live`
- DSN formats: `dbal://` (Doctrine DBAL) or `jackrabbit://` (Jackrabbit)
- Access via `SessionManager` interface

## Testing

**Test Structure**:
- Unit tests: `Tests/Unit/`
- Functional tests: `Tests/Functional/`
- Test application: `Tests/Application/`

**Test Execution**:
```bash
composer phpunit                    # All tests
vendor/bin/phpunit --testsuite=Unit # Unit tests only
```

**CI Environment**:
- Tests run on PHP 8.1, 8.2, 8.3, 8.4
- Matrix includes lowest and highest dependency versions
- MySQL 8.0 required for functional tests

## Common Development Patterns

### Adding a New Parser

1. Create parser class implementing `NodeParserInterface` in `Application/Parser/`
2. Optionally inject `PropertyNodeParser` for property parsing utilities (via constructor)
3. Register service with tag `sulu_phpcr_migration.node_parser` in `Resources/config/parser.xml`
4. Implement `parse(NodeInterface $node, string $documentType): array` with proper array shape docblock
5. Add internal `supports()` logic to check `$documentType` and node mixin types

### Adding a New Persister

1. Create persister class extending `AbstractPersister` in `Application/Persister/`
2. Register service with tag `sulu_phpcr_migration.persister` in `Resources/config/persister.xml`
3. Tag must include `type` attribute (e.g., `type="page"`)
4. Use `$this->entityRepository` for data access
5. Implement all required abstract methods:

**Core methods:**
- `supports(array $document): bool` - check if persister handles this document type
- `getType(): string` - return the document type (e.g., `'page'`)
- `isRoutable(): bool` - whether this content type has routes

**Entity table methods:**
- `getEntityTableName(): string` - main entity table (e.g., `'pa_pages'`)
- `getEntityTableTypes(): array` - Doctrine DBAL column types for entity
- `getEntityMapping(): array` - map document keys to entity columns
- `getEntityResourceKey(): string` - resource key for routes (e.g., `'pages'`)

**Dimension content table methods:**
- `getDimensionContentTableName(): string` - dimension content table
- `getDimensionContentTableTypes(): array` - column types for dimension content
- `getDimensionContentMapping(): array` - map localized data to columns
- `getDimensionContentEntityIdMappingName(): string` - FK column name to entity

**Excerpt junction table methods:**
- `getDimensionContentExcerptCategoriesTableName()` / `getDimensionContentExcerptCategoriesIdName()`
- `getDimensionContentExcerptTagsTableName()` / `getDimensionContentExcerptTagsIdName()`
- `getDimensionContentExcerptAudienceTargetGroupsTableName()` / `getDimensionContentExcerptAudienceTargetGroupsIdName()`

**Junction Table Naming Pattern**: `{prefix}_{type}_dimension_content_excerpt_{relation}`
- Pages: `pa_page_dimension_content_excerpt_categories`
- Articles: `ar_article_dimension_content_excerpt_audience_target_groups`
- Snippets: `sn_snippet_dimension_content_excerpt_tags`

### Handling New PHPCR Property Types

1. Add parsing logic to appropriate parser (or create new one)
2. Use `PropertyParser::trimBlocksToLengths()` for block properties
3. For nested structures, check `PropertyParser::parseProperty()` implementation
4. Add test cases covering the new property structure

## Important Implementation Notes

**Migration Safety**:
- Migrations are idempotent and can be re-run safely
- Content is overwritten, not duplicated
- Supports selective migration by document type
- Always test migrations on non-production data first

**Symfony Integration**:
- Bundle class: `SuluPhpcrMigrationBundle`
- No extension class needed (uses default)
- Services auto-configured via XML
- Compatible with Symfony 6.0 and 7.0

**Dependencies**:
- Requires both Jackalope implementations (doctrine-dbal and jackrabbit)
- Uses Doctrine DBAL for entity persistence
- Symfony Console for command interface
