-- =====================================================================
-- Banco "genealogia" (árvore genealógica) — só a estrutura, sem dados
-- Gerado em 09/10/2026 a partir do banco local (MySQL 8.0.21, mysqldump).
-- Tabelas: pessoas, filiacoes, unioes, eventos, documentos, logs_sistema e usuarios,
-- todas vazias. InnoDB, utf8mb4_unicode_ci.
--
-- Como reproduzir:
--   1) crie um banco vazio:   CREATE DATABASE genealogia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   2) importe este arquivo:  mysql -u root -p genealogia < genealogia.sql   (ou pelo phpMyAdmin > Importar)
--   3) copie includes/config.exemplo.php para includes/config.php e ajuste host/banco/usuário/senha
--   4) crie o seu login:      php criar_usuario.php <usuario> <senha>
-- Atenção: o arquivo APAGA e recria as tabelas acima no banco em que for importado.
-- =====================================================================


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `documentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_pessoa` int NOT NULL,
  `tipo_documento` enum('Certidão Nascimento','Certidão Casamento','Foto','Obito','Passaporte','Outro') COLLATE utf8mb4_unicode_ci NOT NULL,
  `caminho_arquivo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci,
  `data_upload` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `id_pessoa` (`id_pessoa`),
  CONSTRAINT `fk_documentos_pessoa` FOREIGN KEY (`id_pessoa`) REFERENCES `pessoas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `documentos` WRITE;
/*!40000 ALTER TABLE `documentos` DISABLE KEYS */;
/*!40000 ALTER TABLE `documentos` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `eventos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eventos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pessoa_id` int NOT NULL,
  `uniao_id` int DEFAULT NULL,
  `tipo` enum('batismo','imigracao','naturalizacao','residencia','sepultamento','ocupacao','outro') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'outro',
  `data` date DEFAULT NULL,
  `data_qualificador` enum('exata','cerca','antes','depois','entre') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'exata',
  `data_precisao` enum('dia','mes','ano') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dia',
  `data_ate` date DEFAULT NULL,
  `local` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci,
  `documento_id` int DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eventos_pessoa` (`pessoa_id`,`data`),
  KEY `idx_eventos_uniao` (`uniao_id`),
  KEY `idx_eventos_documento` (`documento_id`),
  CONSTRAINT `fk_eventos_documento` FOREIGN KEY (`documento_id`) REFERENCES `documentos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_eventos_pessoa` FOREIGN KEY (`pessoa_id`) REFERENCES `pessoas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eventos_uniao` FOREIGN KEY (`uniao_id`) REFERENCES `unioes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `eventos` WRITE;
/*!40000 ALTER TABLE `eventos` DISABLE KEYS */;
/*!40000 ALTER TABLE `eventos` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `filiacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `filiacoes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `filho_id` int NOT NULL,
  `pai_id` int DEFAULT NULL,
  `mae_id` int DEFAULT NULL,
  `tipo_pai` enum('biologico','adotivo','padrasto','tutela') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'biologico',
  `tipo_mae` enum('biologico','adotivo','padrasto','tutela') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'biologico',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_filiacoes_filho` (`filho_id`),
  KEY `idx_filiacoes_pai` (`pai_id`),
  KEY `idx_filiacoes_mae` (`mae_id`),
  CONSTRAINT `fk_filiacoes_filho` FOREIGN KEY (`filho_id`) REFERENCES `pessoas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_filiacoes_mae` FOREIGN KEY (`mae_id`) REFERENCES `pessoas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_filiacoes_pai` FOREIGN KEY (`pai_id`) REFERENCES `pessoas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `filiacoes` WRITE;
/*!40000 ALTER TABLE `filiacoes` DISABLE KEYS */;
/*!40000 ALTER TABLE `filiacoes` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `logs_sistema`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_sistema` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_usuario_autor` int NOT NULL,
  `acao` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_hora` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detalhes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `logs_sistema` WRITE;
/*!40000 ALTER TABLE `logs_sistema` DISABLE KEYS */;
/*!40000 ALTER TABLE `logs_sistema` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `pessoas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pessoas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome_completo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sexo` enum('M','F','D') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'D',
  `foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `data_nascimento_qualificador` enum('exata','cerca','antes','depois','entre') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'exata',
  `data_nascimento_precisao` enum('dia','mes','ano') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dia',
  `data_nascimento_ate` date DEFAULT NULL,
  `local_nascimento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_falecimento` date DEFAULT NULL,
  `data_falecimento_qualificador` enum('exata','cerca','antes','depois','entre') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'exata',
  `data_falecimento_precisao` enum('dia','mes','ano') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dia',
  `data_falecimento_ate` date DEFAULT NULL,
  `local_falecimento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `biografia` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `pessoas` WRITE;
/*!40000 ALTER TABLE `pessoas` DISABLE KEYS */;
/*!40000 ALTER TABLE `pessoas` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `unioes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `unioes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pessoa1_id` int NOT NULL,
  `pessoa2_id` int NOT NULL,
  `tipo` enum('casamento','uniao_estavel','outro') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'casamento',
  `data_inicio` date DEFAULT NULL,
  `data_inicio_qualificador` enum('exata','cerca','antes','depois','entre') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'exata',
  `data_inicio_precisao` enum('dia','mes','ano') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dia',
  `data_inicio_ate` date DEFAULT NULL,
  `local_inicio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `data_fim_qualificador` enum('exata','cerca','antes','depois','entre') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'exata',
  `data_fim_precisao` enum('dia','mes','ano') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dia',
  `data_fim_ate` date DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unioes_par` (`pessoa1_id`,`pessoa2_id`),
  KEY `idx_unioes_pessoa2` (`pessoa2_id`),
  CONSTRAINT `fk_unioes_pessoa1` FOREIGN KEY (`pessoa1_id`) REFERENCES `pessoas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_unioes_pessoa2` FOREIGN KEY (`pessoa2_id`) REFERENCES `pessoas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `unioes` WRITE;
/*!40000 ALTER TABLE `unioes` DISABLE KEYS */;
/*!40000 ALTER TABLE `unioes` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_completo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `senha` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

