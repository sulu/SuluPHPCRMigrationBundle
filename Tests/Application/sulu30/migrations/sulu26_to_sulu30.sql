SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- RouteBundle
-- ============================================================================

ALTER TABLE ro_routes RENAME TO ro_routes_old;

CREATE TABLE ro_routes (id INT AUTO_INCREMENT NOT NULL, parent_id INT DEFAULT NULL, webspace VARCHAR(31) DEFAULT NULL, locale VARCHAR(15) NOT NULL, slug VARCHAR(144) NOT NULL, resource_key VARCHAR(32) NOT NULL, resource_id VARCHAR(70) NOT NULL, INDEX IDX_671DB7A4727ACA70 (parent_id), INDEX ro_routes_resource_idx (locale, resource_key, resource_id), UNIQUE INDEX ro_routes_unique (webspace, locale, slug), PRIMARY KEY(id));
ALTER TABLE ro_routes ADD CONSTRAINT FK_671DB7A4727ACA70 FOREIGN KEY (parent_id) REFERENCES ro_routes (id) ON DELETE SET NULL;

-- ============================================================================
-- PageBundle
-- ============================================================================

CREATE TABLE pa_page_dimension_contents (title VARCHAR(191) DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, stage VARCHAR(15) NOT NULL, locale VARCHAR(15) DEFAULT NULL, ghostLocale VARCHAR(15) DEFAULT NULL, availableLocales JSON DEFAULT NULL, version INT NOT NULL, shadowLocale VARCHAR(15) DEFAULT NULL, shadowLocales JSON DEFAULT NULL, templateKey VARCHAR(31) DEFAULT NULL, templateData JSON NOT NULL, seoData JSON NOT NULL, seoNoIndex TINYINT(1) NOT NULL, seoNoFollow TINYINT(1) NOT NULL, seoHideInSitemap TINYINT(1) NOT NULL, excerptData JSON NOT NULL, excerptSegment VARCHAR(255) DEFAULT NULL, authored DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', lastModified DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', workflowPlace VARCHAR(31) DEFAULT NULL, workflowPublished DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', linkProvider VARCHAR(32) DEFAULT NULL, linkData JSON DEFAULT NULL, created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', route_id INT DEFAULT NULL, pageUuid VARCHAR(36) NOT NULL, author_id INT DEFAULT NULL, idUsersCreator INT DEFAULT NULL, idUsersChanger INT DEFAULT NULL, INDEX IDX_209A42C034ECB4E6 (route_id), INDEX IDX_209A42C0F099EEF3 (pageUuid), INDEX IDX_209A42C0F675F31B (author_id), INDEX IDX_209A42C0DBF11E1D (idUsersCreator), INDEX IDX_209A42C030D07CD5 (idUsersChanger), INDEX idx_pa_page_dimension_contents_dimension (stage, locale), INDEX idx_pa_page_dimension_contents_locale (locale), INDEX idx_pa_page_dimension_contents_stage (stage), INDEX idx_pa_page_dimension_contents_version (version), INDEX idx_pa_page_dimension_contents_template_key (templateKey), INDEX idx_pa_page_dimension_contents_workflow_place (workflowPlace), INDEX idx_pa_page_dimension_contents_workflow_published (workflowPublished), INDEX idx_pa_page_dimension_contents_link_provider (linkProvider), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE pa_page_dimension_content_excerpt_tags (page_dimension_content_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_66C81FDB67C2CFD5 (page_dimension_content_id), INDEX IDX_66C81FDBBAD26311 (tag_id), PRIMARY KEY(page_dimension_content_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE pa_page_dimension_content_excerpt_categories (page_dimension_content_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_BE45C16867C2CFD5 (page_dimension_content_id), INDEX IDX_BE45C16812469DE2 (category_id), PRIMARY KEY(page_dimension_content_id, category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE pa_page_dimension_content_navigation_contexts (id INT AUTO_INCREMENT NOT NULL, page_dimension_content_id INT NOT NULL, name VARCHAR(31) NOT NULL, INDEX IDX_4C5FD8F767C2CFD5 (page_dimension_content_id), INDEX idx_page_navigation_context (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE pa_pages (uuid VARCHAR(36) NOT NULL, parent_id VARCHAR(36) DEFAULT NULL, webspaceKey VARCHAR(31) NOT NULL, lft INT NOT NULL, rgt INT NOT NULL, depth INT NOT NULL, created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', idUsersCreator INT DEFAULT NULL, idUsersChanger INT DEFAULT NULL, INDEX IDX_FF3DA1E2727ACA70 (parent_id), INDEX IDX_FF3DA1E2DBF11E1D (idUsersCreator), INDEX IDX_FF3DA1E230D07CD5 (idUsersChanger), PRIMARY KEY(uuid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE pa_page_dimension_contents ADD CONSTRAINT FK_209A42C034ECB4E6 FOREIGN KEY (route_id) REFERENCES ro_routes (id) ON DELETE CASCADE;
ALTER TABLE pa_page_dimension_contents ADD CONSTRAINT FK_209A42C0F099EEF3 FOREIGN KEY (pageUuid) REFERENCES pa_pages (uuid) ON DELETE CASCADE;
ALTER TABLE pa_page_dimension_contents ADD CONSTRAINT FK_209A42C0F675F31B FOREIGN KEY (author_id) REFERENCES co_contacts (id) ON DELETE CASCADE;
ALTER TABLE pa_page_dimension_contents ADD CONSTRAINT FK_209A42C0DBF11E1D FOREIGN KEY (idUsersCreator) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE pa_page_dimension_contents ADD CONSTRAINT FK_209A42C030D07CD5 FOREIGN KEY (idUsersChanger) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE pa_page_dimension_content_excerpt_tags ADD CONSTRAINT FK_66C81FDB67C2CFD5 FOREIGN KEY (page_dimension_content_id) REFERENCES pa_page_dimension_contents (id) ON DELETE CASCADE;
ALTER TABLE pa_page_dimension_content_excerpt_tags ADD CONSTRAINT FK_66C81FDBBAD26311 FOREIGN KEY (tag_id) REFERENCES ta_tags (id) ON DELETE CASCADE;
ALTER TABLE pa_page_dimension_content_excerpt_categories ADD CONSTRAINT FK_BE45C16867C2CFD5 FOREIGN KEY (page_dimension_content_id) REFERENCES pa_page_dimension_contents (id) ON DELETE CASCADE;
ALTER TABLE pa_page_dimension_content_excerpt_categories ADD CONSTRAINT FK_BE45C16812469DE2 FOREIGN KEY (category_id) REFERENCES ca_categories (id) ON DELETE CASCADE;
ALTER TABLE pa_page_dimension_content_navigation_contexts ADD CONSTRAINT FK_4C5FD8F767C2CFD5 FOREIGN KEY (page_dimension_content_id) REFERENCES pa_page_dimension_contents (id) ON DELETE CASCADE;
ALTER TABLE pa_pages ADD CONSTRAINT FK_FF3DA1E2727ACA70 FOREIGN KEY (parent_id) REFERENCES pa_pages (uuid) ON DELETE CASCADE;
ALTER TABLE pa_pages ADD CONSTRAINT FK_FF3DA1E2DBF11E1D FOREIGN KEY (idUsersCreator) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE pa_pages ADD CONSTRAINT FK_FF3DA1E230D07CD5 FOREIGN KEY (idUsersChanger) REFERENCES se_users (id) ON DELETE SET NULL;

-- ============================================================================
-- SnippetBundle
-- ============================================================================

CREATE TABLE sn_snippet_dimension_contents (title VARCHAR(191) DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, stage VARCHAR(15) NOT NULL, locale VARCHAR(15) DEFAULT NULL, ghostLocale VARCHAR(15) DEFAULT NULL, availableLocales JSON DEFAULT NULL, version INT NOT NULL, templateKey VARCHAR(31) DEFAULT NULL, templateData JSON NOT NULL, excerptSegment VARCHAR(255) DEFAULT NULL, workflowPlace VARCHAR(31) DEFAULT NULL, workflowPublished DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', snippetUuid VARCHAR(36) NOT NULL, idUsersCreator INT DEFAULT NULL, idUsersChanger INT DEFAULT NULL, INDEX IDX_46D6814477F33FFB (snippetUuid), INDEX IDX_46D68144DBF11E1D (idUsersCreator), INDEX IDX_46D6814430D07CD5 (idUsersChanger), INDEX idx_sn_snippet_dimension_contents_dimension (stage, locale), INDEX idx_sn_snippet_dimension_contents_locale (locale), INDEX idx_sn_snippet_dimension_contents_stage (stage), INDEX idx_sn_snippet_dimension_contents_version (version), INDEX idx_sn_snippet_dimension_contents_template_key (templateKey), INDEX idx_sn_snippet_dimension_contents_workflow_place (workflowPlace), INDEX idx_sn_snippet_dimension_contents_workflow_published (workflowPublished), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE sn_snippet_dimension_content_excerpt_tags (snippet_dimension_content_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_96BD1E357891499D (snippet_dimension_content_id), INDEX IDX_96BD1E35BAD26311 (tag_id), PRIMARY KEY(snippet_dimension_content_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE sn_snippet_dimension_content_excerpt_categories (snippet_dimension_content_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_464EB1547891499D (snippet_dimension_content_id), INDEX IDX_464EB15412469DE2 (category_id), PRIMARY KEY(snippet_dimension_content_id, category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE sn_snippet_area (uuid VARCHAR(255) NOT NULL, webspace_key VARCHAR(255) NOT NULL, area_key VARCHAR(255) NOT NULL, idSnippet VARCHAR(36) DEFAULT NULL, INDEX IDX_8C978EE186A5E727 (idSnippet), PRIMARY KEY(uuid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE sn_snippets (uuid VARCHAR(36) NOT NULL, created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', idUsersCreator INT DEFAULT NULL, idUsersChanger INT DEFAULT NULL, INDEX IDX_E68115CFDBF11E1D (idUsersCreator), INDEX IDX_E68115CF30D07CD5 (idUsersChanger), PRIMARY KEY(uuid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE sn_snippet_dimension_contents ADD CONSTRAINT FK_46D6814477F33FFB FOREIGN KEY (snippetUuid) REFERENCES sn_snippets (uuid) ON DELETE CASCADE;
ALTER TABLE sn_snippet_dimension_contents ADD CONSTRAINT FK_46D68144DBF11E1D FOREIGN KEY (idUsersCreator) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE sn_snippet_dimension_contents ADD CONSTRAINT FK_46D6814430D07CD5 FOREIGN KEY (idUsersChanger) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE sn_snippet_dimension_content_excerpt_tags ADD CONSTRAINT FK_96BD1E357891499D FOREIGN KEY (snippet_dimension_content_id) REFERENCES sn_snippet_dimension_contents (id) ON DELETE CASCADE;
ALTER TABLE sn_snippet_dimension_content_excerpt_tags ADD CONSTRAINT FK_96BD1E35BAD26311 FOREIGN KEY (tag_id) REFERENCES ta_tags (id) ON DELETE CASCADE;
ALTER TABLE sn_snippet_dimension_content_excerpt_categories ADD CONSTRAINT FK_464EB1547891499D FOREIGN KEY (snippet_dimension_content_id) REFERENCES sn_snippet_dimension_contents (id) ON DELETE CASCADE;
ALTER TABLE sn_snippet_dimension_content_excerpt_categories ADD CONSTRAINT FK_464EB15412469DE2 FOREIGN KEY (category_id) REFERENCES ca_categories (id) ON DELETE CASCADE;
ALTER TABLE sn_snippets ADD CONSTRAINT FK_E68115CFDBF11E1D FOREIGN KEY (idUsersCreator) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE sn_snippet_area ADD CONSTRAINT FK_8C978EE186A5E727 FOREIGN KEY (idSnippet) REFERENCES sn_snippets (uuid) ON DELETE CASCADE;
ALTER TABLE sn_snippets ADD CONSTRAINT FK_E68115CF30D07CD5 FOREIGN KEY (idUsersChanger) REFERENCES se_users (id) ON DELETE SET NULL;

-- ============================================================================
-- ArticleBundle
-- ============================================================================

CREATE TABLE ar_article_dimension_contents (title VARCHAR(191) DEFAULT NULL, customizeWebspaceSettings TINYINT(1) NOT NULL, id INT AUTO_INCREMENT NOT NULL, stage VARCHAR(15) NOT NULL, locale VARCHAR(15) DEFAULT NULL, ghostLocale VARCHAR(15) DEFAULT NULL, availableLocales JSON DEFAULT NULL, version INT NOT NULL, shadowLocale VARCHAR(15) DEFAULT NULL, shadowLocales JSON DEFAULT NULL, templateKey VARCHAR(31) DEFAULT NULL, templateData JSON NOT NULL, seoData JSON NOT NULL, seoNoIndex TINYINT(1) NOT NULL, seoNoFollow TINYINT(1) NOT NULL, seoHideInSitemap TINYINT(1) NOT NULL, excerptData JSON NOT NULL, excerptSegment VARCHAR(255) DEFAULT NULL, mainWebspace VARCHAR(255) DEFAULT NULL, authored DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', lastModified DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', workflowPlace VARCHAR(31) DEFAULT NULL, workflowPublished DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', route_id INT DEFAULT NULL, articleUuid VARCHAR(36) NOT NULL, author_id INT DEFAULT NULL, idUsersCreator INT DEFAULT NULL, idUsersChanger INT DEFAULT NULL, INDEX IDX_5674F7BF34ECB4E6 (route_id), INDEX IDX_5674F7BFAE39C518 (articleUuid), INDEX IDX_5674F7BFF675F31B (author_id), INDEX IDX_5674F7BFDBF11E1D (idUsersCreator), INDEX IDX_5674F7BF30D07CD5 (idUsersChanger), INDEX idx_ar_article_dimension_contents_dimension (stage, locale), INDEX idx_ar_article_dimension_contents_locale (locale), INDEX idx_ar_article_dimension_contents_stage (stage), INDEX idx_ar_article_dimension_contents_version (version), INDEX idx_ar_article_dimension_contents_template_key (templateKey), INDEX idx_ar_article_dimension_contents_workflow_place (workflowPlace), INDEX idx_ar_article_dimension_contents_workflow_published (workflowPublished), PRIMARY KEY(id));
CREATE TABLE ar_article_dimension_content_excerpt_tags (article_dimension_content_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_B45854027C1747D1 (article_dimension_content_id), INDEX IDX_B4585402BAD26311 (tag_id), PRIMARY KEY(article_dimension_content_id, tag_id));
CREATE TABLE ar_article_dimension_content_excerpt_categories (article_dimension_content_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_971AE52D7C1747D1 (article_dimension_content_id), INDEX IDX_971AE52D12469DE2 (category_id), PRIMARY KEY(article_dimension_content_id, category_id));
CREATE TABLE ar_article_dimension_content_additional_webspaces (name VARCHAR(31) NOT NULL, id INT AUTO_INCREMENT NOT NULL, article_dimension_content_id INT NOT NULL, INDEX IDX_3F9F33F37C1747D1 (article_dimension_content_id), INDEX idx_article_additional_webspace (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE ar_articles (uuid VARCHAR(36) NOT NULL, created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', idUsersCreator INT DEFAULT NULL, idUsersChanger INT DEFAULT NULL, INDEX IDX_7F75CD17DBF11E1D (idUsersCreator), INDEX IDX_7F75CD1730D07CD5 (idUsersChanger), PRIMARY KEY(uuid));
ALTER TABLE ar_article_dimension_contents ADD CONSTRAINT FK_5674F7BF34ECB4E6 FOREIGN KEY (route_id) REFERENCES ro_routes (id) ON DELETE CASCADE;
ALTER TABLE ar_article_dimension_contents ADD CONSTRAINT FK_5674F7BFAE39C518 FOREIGN KEY (articleUuid) REFERENCES ar_articles (uuid) ON DELETE CASCADE;
ALTER TABLE ar_article_dimension_contents ADD CONSTRAINT FK_5674F7BFF675F31B FOREIGN KEY (author_id) REFERENCES co_contacts (id) ON DELETE CASCADE;
ALTER TABLE ar_article_dimension_contents ADD CONSTRAINT FK_5674F7BFDBF11E1D FOREIGN KEY (idUsersCreator) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE ar_article_dimension_contents ADD CONSTRAINT FK_5674F7BF30D07CD5 FOREIGN KEY (idUsersChanger) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE ar_article_dimension_content_excerpt_tags ADD CONSTRAINT FK_B45854027C1747D1 FOREIGN KEY (article_dimension_content_id) REFERENCES ar_article_dimension_contents (id) ON DELETE CASCADE;
ALTER TABLE ar_article_dimension_content_excerpt_tags ADD CONSTRAINT FK_B4585402BAD26311 FOREIGN KEY (tag_id) REFERENCES ta_tags (id) ON DELETE CASCADE;
ALTER TABLE ar_article_dimension_content_excerpt_categories ADD CONSTRAINT FK_971AE52D7C1747D1 FOREIGN KEY (article_dimension_content_id) REFERENCES ar_article_dimension_contents (id) ON DELETE CASCADE;
ALTER TABLE ar_article_dimension_content_excerpt_categories ADD CONSTRAINT FK_971AE52D12469DE2 FOREIGN KEY (category_id) REFERENCES ca_categories (id) ON DELETE CASCADE;
ALTER TABLE ar_article_dimension_content_additional_webspaces ADD CONSTRAINT FK_3F9F33F37C1747D1 FOREIGN KEY (article_dimension_content_id) REFERENCES ar_article_dimension_contents (id) ON DELETE CASCADE;
ALTER TABLE ar_articles ADD CONSTRAINT FK_7F75CD17DBF11E1D FOREIGN KEY (idUsersCreator) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE ar_articles ADD CONSTRAINT FK_7F75CD1730D07CD5 FOREIGN KEY (idUsersChanger) REFERENCES se_users (id) ON DELETE SET NULL;

-- ============================================================================
-- CustomUrlBundle
-- ============================================================================

CREATE TABLE cu_custom_url (uuid VARCHAR(36) NOT NULL, title VARCHAR(255) NOT NULL, published TINYINT(1) NOT NULL, base_domain VARCHAR(255) NOT NULL, webspace VARCHAR(255) NOT NULL, domain_parts JSON NOT NULL, target_document VARCHAR(255) DEFAULT NULL, target_locale VARCHAR(255) NOT NULL, canonical TINYINT(1) NOT NULL, redirect TINYINT(1) NOT NULL, no_follow TINYINT(1) NOT NULL, no_index TINYINT(1) NOT NULL, created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', idUsersCreator INT DEFAULT NULL, idUsersChanger INT DEFAULT NULL, UNIQUE INDEX UNIQ_51A7F98D2B36786B (title), INDEX IDX_51A7F98DDBF11E1D (idUsersCreator), INDEX IDX_51A7F98D30D07CD5 (idUsersChanger), INDEX IDX_51A7F98DB055BCD4 (webspace), PRIMARY KEY(uuid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE cu_custom_url_route (uuid VARCHAR(36) NOT NULL, customUrl VARCHAR(36) NOT NULL, target_route_uuid VARCHAR(36) DEFAULT NULL, path VARCHAR(255) NOT NULL, history TINYINT(1) DEFAULT 0 NOT NULL, created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_D2349CF4CB30A644 (customUrl), INDEX IDX_D2349CF44ED689B2 (target_route_uuid), INDEX custom_url_route_history_idx (history), UNIQUE INDEX cu_custom_url_route_unique (path), PRIMARY KEY(uuid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE cu_custom_url ADD CONSTRAINT FK_51A7F98DDBF11E1D FOREIGN KEY (idUsersCreator) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE cu_custom_url ADD CONSTRAINT FK_51A7F98D30D07CD5 FOREIGN KEY (idUsersChanger) REFERENCES se_users (id) ON DELETE SET NULL;
ALTER TABLE cu_custom_url_route ADD CONSTRAINT FK_D2349CF4CB30A644 FOREIGN KEY (customUrl) REFERENCES cu_custom_url (uuid) ON DELETE CASCADE;
ALTER TABLE cu_custom_url_route ADD CONSTRAINT FK_D2349CF44ED689B2 FOREIGN KEY (target_route_uuid) REFERENCES cu_custom_url_route (uuid) ON DELETE CASCADE;

-- ============================================================================
-- MediaBundle
-- ============================================================================

ALTER TABLE me_file_version_content_languages DROP FOREIGN KEY FK_F3FD652C911ADE33;
ALTER TABLE me_file_version_publish_languages DROP FOREIGN KEY FK_195DAB3C911ADE33;
DROP TABLE me_file_version_content_languages;
DROP TABLE me_file_version_publish_languages;

ALTER TABLE me_media ADD COLUMN type VARCHAR(10) DEFAULT NULL;
UPDATE me_media m
    JOIN me_media_types mt ON mt.id = m.idMediaTypes
    SET m.type = mt.name;

ALTER TABLE me_media DROP FOREIGN KEY FK_A694E57284671716;
DROP TABLE me_media_types;
DROP INDEX IDX_A694E57284671716 ON me_media;

ALTER TABLE me_media MODIFY type VARCHAR(10) NOT NULL;
ALTER TABLE me_media DROP idMediaTypes;

CREATE INDEX IDX_A694E5728CDE5729 ON me_media (type);

-- ============================================================================
-- CategoryBundle
-- ============================================================================

ALTER TABLE ca_category_meta DROP FOREIGN KEY FK_2575BBB0B8075882;
DROP TABLE ca_category_meta;

-- ============================================================================
-- SecurityType removal
-- ============================================================================

ALTER TABLE se_roles DROP FOREIGN KEY FK_13B749A0D02106C0;
DROP TABLE se_security_types;
DROP INDEX IDX_13B749A0D02106C0 ON se_roles;
ALTER TABLE se_roles DROP idSecurityTypes;

-- ============================================================================
-- Groups removal
-- ============================================================================

ALTER TABLE se_group_roles DROP FOREIGN KEY FK_9713F725937C91EA;
ALTER TABLE se_group_roles DROP FOREIGN KEY FK_9713F725A1FA6DDA;
ALTER TABLE se_groups DROP FOREIGN KEY FK_231E44EC30D07CD5;
ALTER TABLE se_groups DROP FOREIGN KEY FK_231E44ECBF274AB0;
ALTER TABLE se_groups DROP FOREIGN KEY FK_231E44ECDBF11E1D;
ALTER TABLE se_user_groups DROP FOREIGN KEY FK_E43ED0C8347E6F4;
ALTER TABLE se_user_groups DROP FOREIGN KEY FK_E43ED0C8937C91EA;
DROP TABLE se_group_roles;
DROP TABLE se_groups;
DROP TABLE se_user_groups;

-- ============================================================================
-- User entity migration
-- ============================================================================

ALTER TABLE se_users CHANGE idContacts idContacts INT DEFAULT NULL;

-- ============================================================================
-- TimestampableInterface migration
-- ============================================================================

ALTER TABLE re_references CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE tr_trash_items CHANGE storeTimestamp storeTimestamp DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE ac_activities CHANGE timestamp timestamp DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE pr_preview_links CHANGE lastVisit lastVisit DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE co_accounts CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE co_contacts CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE me_files CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE me_collections CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE me_media CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE me_file_versions CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE se_users CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE se_roles CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE ca_category_translations CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE ca_categories CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE ca_keywords CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';
ALTER TABLE ta_tags CHANGE created created DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE changed changed DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)';

-- ============================================================================
-- Permission migration
-- ============================================================================

UPDATE `se_permissions` SET `context` = 'sulu.article.articles' WHERE `context` = 'sulu.modules.articles';
UPDATE `se_permissions` SET `context` = 'sulu.snippet.snippets' WHERE `context` = 'sulu.global.snippets';

-- ============================================================================
-- Legacy user settings removal
-- ============================================================================

DELETE FROM se_user_settings WHERE settingsKey LIKE 'sulu_admin.list_store.articles%';
DELETE FROM se_user_settings WHERE settingsKey LIKE 'sulu_admin.list_store.snippets%';
DELETE FROM se_user_settings WHERE settingsKey LIKE 'sulu_admin.list_store.pages%';

-- ============================================================================
-- Removing "modules" from Permissions
-- ============================================================================

DROP INDEX UNIQ_5CEC3EEAE25D857EC242628A1FA6DDA ON se_permissions;
ALTER TABLE se_permissions DROP module;
CREATE UNIQUE INDEX UNIQ_5CEC3EEAE25D857EA1FA6DDA ON se_permissions (context, idRoles);

SET FOREIGN_KEY_CHECKS = 1;
