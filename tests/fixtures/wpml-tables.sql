-- WPML's tables, as the migration's tests read them (tests/migrate.test.php):
-- their structure, and its languages and locales. For a site with no WPML ever
-- installed: `composer test:sqlite` loads them.
CREATE TABLE `wp_icl_languages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(7) NOT NULL,
  `english_name` varchar(128) NOT NULL,
  `major` tinyint(4) NOT NULL DEFAULT 0,
  `active` tinyint(4) NOT NULL,
  `default_locale` varchar(35) DEFAULT NULL,
  `tag` varchar(35) DEFAULT NULL,
  `encode_url` tinyint(1) NOT NULL DEFAULT 0,
  `country` varchar(10) DEFAULT NULL,
  `display_code` varchar(40) DEFAULT NULL,
  `is_custom` tinyint(4) NOT NULL DEFAULT 0,
  `type` varchar(20) DEFAULT NULL,
  `translation_paused` tinyint(1) NOT NULL DEFAULT 0,
  `bcp_47` varchar(35) DEFAULT NULL,
  `is_rtl` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `english_name` (`english_name`),
  KEY `bcp_47` (`bcp_47`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
CREATE TABLE `wp_icl_languages_translations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `language_code` varchar(7) NOT NULL,
  `display_language_code` varchar(7) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `language_code` (`language_code`,`display_language_code`)
) ENGINE=InnoDB AUTO_INCREMENT=4293 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
CREATE TABLE `wp_icl_locale_map` (
  `code` varchar(7) NOT NULL,
  `locale` varchar(35) NOT NULL,
  PRIMARY KEY (`code`,`locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
CREATE TABLE `wp_icl_strings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `language` varchar(7) NOT NULL,
  `context` varchar(160) NOT NULL,
  `name` varchar(160) NOT NULL,
  `value` longtext NOT NULL,
  `string_package_id` bigint(20) unsigned DEFAULT NULL,
  `location` bigint(20) unsigned DEFAULT NULL,
  `wrap_tag` varchar(16) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'LINE',
  `title` text DEFAULT NULL,
  `status` tinyint(4) NOT NULL,
  `gettext_context` text NOT NULL,
  `domain_name_context_md5` varchar(32) NOT NULL,
  `translation_priority` varchar(160) NOT NULL,
  `word_count` int(10) unsigned DEFAULT NULL,
  `has_text` tinyint(1) NOT NULL DEFAULT 1,
  `string_type` tinyint(4) NOT NULL DEFAULT 0,
  `component_id` varchar(500) DEFAULT NULL,
  `component_type` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uc_domain_name_context_md5` (`domain_name_context_md5`),
  KEY `language_context` (`language`,`context`),
  KEY `icl_strings_name` (`name`),
  KEY `icl_strings_translation_priority` (`translation_priority`),
  KEY `context` (`context`),
  KEY `context_has_text` (`context`,`has_text`),
  KEY `string_package_id` (`string_package_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1358 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
CREATE TABLE `wp_icl_string_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `string_id` bigint(20) unsigned NOT NULL,
  `language` varchar(10) NOT NULL,
  `status` tinyint(4) NOT NULL,
  `value` longtext DEFAULT NULL,
  `mo_string` longtext DEFAULT NULL,
  `translator_id` bigint(20) unsigned DEFAULT NULL,
  `translation_service` varchar(16) NOT NULL DEFAULT '',
  `batch_id` int(11) NOT NULL DEFAULT 0,
  `translation_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `string_language` (`string_id`,`language`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=186 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
CREATE TABLE `wp_icl_translations` (
  `translation_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `element_type` varchar(60) NOT NULL DEFAULT 'post_post',
  `element_id` bigint(20) DEFAULT NULL,
  `trid` bigint(20) NOT NULL,
  `language_code` varchar(7) NOT NULL,
  `source_language_code` varchar(7) DEFAULT NULL,
  PRIMARY KEY (`translation_id`),
  UNIQUE KEY `trid_lang` (`trid`,`language_code`),
  UNIQUE KEY `el_type_id` (`element_type`,`element_id`),
  KEY `trid` (`trid`),
  KEY `id_type_language` (`element_id`,`element_type`,`language_code`)
) ENGINE=InnoDB AUTO_INCREMENT=768 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_icl_languages` VALUES (1,'en','English (en)',1,0,'en_US','en',0,NULL,NULL,0,'international',0,'en',NULL);
INSERT INTO `wp_icl_languages` VALUES (2,'es','Spanish',1,0,'es_ES','es',0,'ES',NULL,0,'national',0,'es',NULL);
INSERT INTO `wp_icl_languages` VALUES (3,'de','German',1,0,'de_DE','de',0,'DE',NULL,0,'national',0,'de',NULL);
INSERT INTO `wp_icl_languages` VALUES (4,'fr','French',1,0,'fr_FR','fr',0,'FR',NULL,0,'national',0,'fr',NULL);
INSERT INTO `wp_icl_languages` VALUES (5,'ar','Arabic',0,0,'ar','ar',0,NULL,NULL,0,'international',0,'ar',1);
INSERT INTO `wp_icl_languages` VALUES (6,'bs','Bosnian',0,0,'bs_BA','bs',0,'BA',NULL,0,'national',0,'bs',NULL);
INSERT INTO `wp_icl_languages` VALUES (7,'bg','Bulgarian',0,0,'bg_BG','bg',0,'BG',NULL,0,'national',0,'bg',NULL);
INSERT INTO `wp_icl_languages` VALUES (8,'ca','Catalan',0,0,'ca','ca',0,NULL,NULL,0,'regional',0,'ca',NULL);
INSERT INTO `wp_icl_languages` VALUES (9,'cs','Czech',0,0,'cs_CZ','cs',0,'CZ',NULL,0,'national',0,'cs',NULL);
INSERT INTO `wp_icl_languages` VALUES (10,'sk','Slovak',0,0,'sk_SK','sk',0,'SK',NULL,0,'national',0,'sk',NULL);
INSERT INTO `wp_icl_languages` VALUES (11,'cy','Welsh',0,0,'cy','cy',0,NULL,NULL,0,'regional',0,'cy',NULL);
INSERT INTO `wp_icl_languages` VALUES (12,'da','Danish',1,0,'da_DK','da',0,'DK',NULL,0,'national',0,'da',NULL);
INSERT INTO `wp_icl_languages` VALUES (13,'el','Greek',0,0,'el','el',0,'GR',NULL,0,'national',0,'el',NULL);
INSERT INTO `wp_icl_languages` VALUES (14,'eo','Esperanto',0,0,'eo','eo',0,NULL,NULL,0,'international',0,'eo',NULL);
INSERT INTO `wp_icl_languages` VALUES (15,'et','Estonian',0,0,'et','et',0,'EE',NULL,0,'national',0,'et',NULL);
INSERT INTO `wp_icl_languages` VALUES (16,'eu','Basque',0,0,'eu','eu',0,NULL,NULL,0,'regional',0,'eu',NULL);
INSERT INTO `wp_icl_languages` VALUES (17,'fa','Persian',0,0,'fa_IR','fa',0,'IR',NULL,0,'national',0,'fa',1);
INSERT INTO `wp_icl_languages` VALUES (18,'fi','Finnish',0,0,'fi','fi',0,'FI',NULL,0,'national',0,'fi',NULL);
INSERT INTO `wp_icl_languages` VALUES (19,'ga','Irish',0,0,'ga','ga',0,'IE',NULL,0,'national',0,'ga',NULL);
INSERT INTO `wp_icl_languages` VALUES (20,'he','Hebrew',0,0,'he_IL','he',0,'IL',NULL,0,'national',0,'he',1);
INSERT INTO `wp_icl_languages` VALUES (21,'hi','Hindi',0,0,'hi_IN','hi',0,'IN',NULL,0,'national',0,'hi',NULL);
INSERT INTO `wp_icl_languages` VALUES (22,'hr','Croatian',0,0,'hr','hr',0,'HR',NULL,0,'national',0,'hr',NULL);
INSERT INTO `wp_icl_languages` VALUES (23,'hu','Hungarian',0,0,'hu_HU','hu',0,'HU',NULL,0,'national',0,'hu',NULL);
INSERT INTO `wp_icl_languages` VALUES (24,'hy','Armenian',0,0,'hy','hy',0,'AM',NULL,0,'national',0,'hy',NULL);
INSERT INTO `wp_icl_languages` VALUES (25,'id','Indonesian',0,0,'id_ID','id',0,'ID',NULL,0,'national',0,'id',NULL);
INSERT INTO `wp_icl_languages` VALUES (26,'is','Icelandic',0,0,'is_IS','is',0,'IS',NULL,0,'national',0,'is',NULL);
INSERT INTO `wp_icl_languages` VALUES (27,'it','Italian',1,1,'it_IT','it-IT',0,'IT','it',0,'national',0,'it-IT',NULL);
INSERT INTO `wp_icl_languages` VALUES (28,'ja','Japanese',1,0,'ja','ja',0,'JP',NULL,0,'national',0,'ja',NULL);
INSERT INTO `wp_icl_languages` VALUES (29,'ko','Korean',0,0,'ko_KR','ko',0,'KR',NULL,0,'national',0,'ko',NULL);
INSERT INTO `wp_icl_languages` VALUES (30,'ku','Kurdish',0,0,'ckb','ku',0,NULL,NULL,0,'regional',0,'ku',1);
INSERT INTO `wp_icl_languages` VALUES (31,'lv','Latvian',0,0,'lv','lv',0,'LV',NULL,0,'national',0,'lv',NULL);
INSERT INTO `wp_icl_languages` VALUES (32,'lt','Lithuanian',0,0,'lt_LT','lt',0,'LT',NULL,0,'national',0,'lt',NULL);
INSERT INTO `wp_icl_languages` VALUES (33,'mk','Macedonian',0,0,'mk_MK','mk',0,'MK',NULL,0,'national',0,'mk',NULL);
INSERT INTO `wp_icl_languages` VALUES (34,'mt','Maltese',0,0,'mlt','mt',0,'MT',NULL,0,'national',0,'mt',NULL);
INSERT INTO `wp_icl_languages` VALUES (35,'mn','Mongolian',0,0,'mn','mn',0,'MN',NULL,0,'national',0,'mn',NULL);
INSERT INTO `wp_icl_languages` VALUES (36,'ne','Nepali',0,0,'ne_NP','ne',0,'NP',NULL,0,'national',0,'ne',NULL);
INSERT INTO `wp_icl_languages` VALUES (37,'nl','Dutch',1,0,'nl_NL','nl',0,'NL',NULL,0,'national',0,'nl',NULL);
INSERT INTO `wp_icl_languages` VALUES (38,'no','Norwegian Bokmål',0,0,'nb_NO','no',0,'NO',NULL,0,'national',0,'no',NULL);
INSERT INTO `wp_icl_languages` VALUES (39,'pa','Punjabi',0,0,'pa','pa',0,NULL,NULL,0,'regional',0,'pa',NULL);
INSERT INTO `wp_icl_languages` VALUES (40,'pl','Polish',0,0,'pl_PL','pl',0,'PL',NULL,0,'national',0,'pl',NULL);
INSERT INTO `wp_icl_languages` VALUES (41,'pt-pt','Portuguese, Portugal',0,0,'pt_PT','pt-pt',0,'PT',NULL,0,'national',0,'pt-PT',NULL);
INSERT INTO `wp_icl_languages` VALUES (42,'pt-br','Portuguese, Brazil',0,0,'pt_BR','pt-br',0,'BR',NULL,0,'national',0,'pt-BR',NULL);
INSERT INTO `wp_icl_languages` VALUES (43,'ro','Romanian',0,0,'ro_RO','ro',0,'RO',NULL,0,'national',0,'ro',NULL);
INSERT INTO `wp_icl_languages` VALUES (44,'ru','Russian',1,0,'ru_RU','ru',0,'RU',NULL,0,'national',0,'ru',NULL);
INSERT INTO `wp_icl_languages` VALUES (45,'sl','Slovenian',0,0,'sl_SI','sl',0,'SI',NULL,0,'national',0,'sl',NULL);
INSERT INTO `wp_icl_languages` VALUES (46,'so','Somali',0,0,'so_SO','so',0,'SO',NULL,0,'national',0,'so',NULL);
INSERT INTO `wp_icl_languages` VALUES (47,'sq','Albanian',0,0,'sq','sq',0,'AL',NULL,0,'national',0,'sq',NULL);
INSERT INTO `wp_icl_languages` VALUES (48,'sr','Serbian',0,0,'sr_RS','sr',0,'RS',NULL,0,'national',0,'sr',NULL);
INSERT INTO `wp_icl_languages` VALUES (49,'sv','Swedish',0,0,'sv_SE','sv',0,'SE',NULL,0,'national',0,'sv',NULL);
INSERT INTO `wp_icl_languages` VALUES (50,'ta','Tamil',0,0,'ta_IN','ta',0,NULL,NULL,0,'regional',0,'ta',NULL);
INSERT INTO `wp_icl_languages` VALUES (51,'th','Thai',0,0,'th','th',0,'TH',NULL,0,'national',0,'th',NULL);
INSERT INTO `wp_icl_languages` VALUES (52,'tr','Turkish',0,0,'tr_TR','tr',0,'TR',NULL,0,'national',0,'tr',NULL);
INSERT INTO `wp_icl_languages` VALUES (53,'uk','Ukrainian',0,0,'uk','uk',0,'UA',NULL,0,'national',0,'uk',NULL);
INSERT INTO `wp_icl_languages` VALUES (54,'ur','Urdu',0,0,'ur','ur',0,'PK',NULL,0,'national',0,'ur',1);
INSERT INTO `wp_icl_languages` VALUES (55,'uz','Uzbek',0,0,'uz_UZ','uz',0,'UZ',NULL,0,'national',0,'uz',NULL);
INSERT INTO `wp_icl_languages` VALUES (56,'vi','Vietnamese',0,0,'vi','vi',0,'VN',NULL,0,'national',0,'vi',NULL);
INSERT INTO `wp_icl_languages` VALUES (57,'yi','Yiddish',0,0,'','yi',0,NULL,NULL,0,'international',0,'yi',NULL);
INSERT INTO `wp_icl_languages` VALUES (58,'zh-hans','Chinese (Simplified)',1,0,'zh_CN','zh-hans',0,'CN',NULL,0,'national',0,'zh-Hans',NULL);
INSERT INTO `wp_icl_languages` VALUES (59,'zu','Zulu',0,0,'zul','zu',0,NULL,NULL,0,'regional',0,'zu',NULL);
INSERT INTO `wp_icl_languages` VALUES (60,'zh-hant','Chinese (Traditional)',1,0,'zh_TW','zh-hant',0,'TW',NULL,0,'national',0,'zh-Hant',NULL);
INSERT INTO `wp_icl_languages` VALUES (61,'ms','Malay',0,0,'ms_MY','ms',0,'MY',NULL,0,'national',0,'ms',NULL);
INSERT INTO `wp_icl_languages` VALUES (62,'gl','Galician',0,0,'gl_ES','gl',0,NULL,NULL,0,'regional',0,'gl',NULL);
INSERT INTO `wp_icl_languages` VALUES (63,'bn','Bengali',0,0,'bn_BD','bn',0,'BD',NULL,0,'national',0,'bn',NULL);
INSERT INTO `wp_icl_languages` VALUES (64,'az','Azerbaijani',0,0,'az','az',0,'AZ',NULL,0,'national',0,'az',NULL);
INSERT INTO `wp_icl_languages` VALUES (65,'sw','Swahili',0,0,'sw','sw',0,NULL,NULL,0,'international',0,'sw',NULL);
INSERT INTO `wp_icl_languages` VALUES (66,'en-gb','English',0,1,'en_GB','en-GB',0,'GB','en',0,'international',0,'en-GB',NULL);
COMMIT;
INSERT INTO `wp_icl_locale_map` VALUES ('en','en_US');
INSERT INTO `wp_icl_locale_map` VALUES ('en-gb','en_GB');
INSERT INTO `wp_icl_locale_map` VALUES ('it','it_IT');
COMMIT;
