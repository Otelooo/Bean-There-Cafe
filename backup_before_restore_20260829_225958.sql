-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: localhost    Database: bean_there_cafe
-- ------------------------------------------------------
-- Server version	8.4.3

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

--
-- Table structure for table `product_category`
--

DROP TABLE IF EXISTS `product_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_category` (
  `product_category_id` int NOT NULL AUTO_INCREMENT,
  `product_category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`product_category_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_category`
--

LOCK TABLES `product_category` WRITE;
/*!40000 ALTER TABLE `product_category` DISABLE KEYS */;
INSERT INTO `product_category` (`product_category_id`, `product_category`) VALUES (15,'Appetizers'),(16,'Burgers & Sandwiches'),(17,'Drinks'),(18,'Salads'),(19,'Soups'),(20,'Rice Meals'),(21,'Budget Meals'),(22,'Share It'),(23,'Seafood'),(24,'Wings & Rice'),(25,'Pizza'),(26,'Pasta Dishes');
/*!40000 ALTER TABLE `product_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_ingredient_items`
--

DROP TABLE IF EXISTS `product_ingredient_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_ingredient_items` (
  `product_ingredient_items_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `product_ingredients_id` int NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `is_flavor_choice` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`product_ingredient_items_id`) USING BTREE,
  KEY `idx_ingredient_items_product` (`product_id`) USING BTREE,
  KEY `fk_ingredient_items_ingredient` (`product_ingredients_id`) USING BTREE,
  CONSTRAINT `fk_ingredient_items_ingredient` FOREIGN KEY (`product_ingredients_id`) REFERENCES `product_ingredients` (`product_ingredients_id`),
  CONSTRAINT `fk_ingredient_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_ingredient_items`
--

LOCK TABLES `product_ingredient_items` WRITE;
/*!40000 ALTER TABLE `product_ingredient_items` DISABLE KEYS */;
INSERT INTO `product_ingredient_items` (`product_ingredient_items_id`, `product_id`, `product_ingredients_id`, `quantity`, `unit`, `is_flavor_choice`) VALUES (16,17,13,100.00,'g',0),(17,17,14,210.00,'g',0),(19,18,8,30.00,'g',0),(20,19,19,2.00,'tbsp',0),(21,19,17,10.00,'g',0),(22,18,16,30.00,'ml',0);
/*!40000 ALTER TABLE `product_ingredient_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_ingredients`
--

DROP TABLE IF EXISTS `product_ingredients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_ingredients` (
  `product_ingredients_id` int NOT NULL AUTO_INCREMENT,
  `ingredient_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `ingredient_stock` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ingredient_unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `ingredient_supplier` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `ingredient_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  PRIMARY KEY (`product_ingredients_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_ingredients`
--

LOCK TABLES `product_ingredients` WRITE;
/*!40000 ALTER TABLE `product_ingredients` DISABLE KEYS */;
INSERT INTO `product_ingredients` (`product_ingredients_id`, `ingredient_name`, `ingredient_stock`, `ingredient_unit`, `ingredient_supplier`, `ingredient_contact`) VALUES (8,'Fries',19.97,'kg','Potato Factory','09123456789'),(9,'Ground Pork',50.00,'kg','Pork Company','0987654321'),(10,'Cheese Sauce',20.00,'kg','cheese factory','09123456789'),(11,'Tomato',15.00,'kg','Farm','09125762891'),(12,'Cucumber',15.00,'kg','Farm','09125762891'),(13,'Pork',25.00,'kg','Pork Company','09123456789'),(14,'Rice',100.00,'kg','Rice Mill','09918371627'),(15,'Liempo',15.00,'kg','Pork Company','09125762891'),(16,'Cooking oil',19.97,'l','Oil Company','09918371627'),(17,'espresso',19.99,'kg','Expresso Comp.','09871625357'),(18,'milk',20.00,'ml','Milk comp.','09918371627'),(19,'caramel syrup',18.00,'tbsp','Syrup','0987654321');
/*!40000 ALTER TABLE `product_ingredients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_supplier`
--

DROP TABLE IF EXISTS `product_supplier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_supplier` (
  `product_supplier_id` int NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `supplier_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`product_supplier_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_supplier`
--

LOCK TABLES `product_supplier` WRITE;
/*!40000 ALTER TABLE `product_supplier` DISABLE KEYS */;
INSERT INTO `product_supplier` (`product_supplier_id`, `supplier_name`, `supplier_contact`) VALUES (12,'In-house','—');
/*!40000 ALTER TABLE `product_supplier` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variants`
--

DROP TABLE IF EXISTS `product_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variants` (
  `product_variant_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `variant_name` varchar(50) NOT NULL,
  `variant_price` decimal(10,2) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`product_variant_id`),
  KEY `idx_variant_product` (`product_id`),
  CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variants`
--

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
INSERT INTO `product_variants` (`product_variant_id`, `product_id`, `variant_name`, `variant_price`, `sort_order`) VALUES (5,19,'Hot',59.00,0),(6,19,'Iced',59.00,1);
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `product_id` int NOT NULL AUTO_INCREMENT,
  `product_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `product_category_id` int NOT NULL,
  `product_stocks` int DEFAULT NULL,
  `product_cost` decimal(10,2) NOT NULL,
  `product_selling_price` decimal(10,2) NOT NULL,
  `product_supplier_id` int NOT NULL,
  `product_type` enum('made_to_order','prepared') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'made_to_order',
  `product_image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`product_id`) USING BTREE,
  KEY `fk_products_category` (`product_category_id`) USING BTREE,
  KEY `idx_products_supplier` (`product_supplier_id`) USING BTREE,
  CONSTRAINT `fk_products_category` FOREIGN KEY (`product_category_id`) REFERENCES `product_category` (`product_category_id`),
  CONSTRAINT `fk_products_supplier` FOREIGN KEY (`product_supplier_id`) REFERENCES `product_supplier` (`product_supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` (`product_id`, `product_name`, `product_category_id`, `product_stocks`, `product_cost`, `product_selling_price`, `product_supplier_id`, `product_type`, `product_image`) VALUES (17,'Grilled Liempo',20,50,5000.00,130.00,12,'made_to_order','uploads/products/prod_6a868678d8efd8.51445215.webp'),(18,'French Fries',15,99,5000.00,30.00,12,'made_to_order','uploads/products/prod_6a8687de07ff58.11770697.webp'),(19,'Caramel Macchiato',17,19,500.00,59.00,12,'made_to_order','uploads/products/prod_6a868970a78132.26850425.webp');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('cafe_address',''),('cafe_contact',''),('cafe_name','Bean There Café'),('critical_stock_threshold','5'),('discount_rate','0.2000'),('ewallet_qr_image',''),('low_stock_threshold','10'),('receipt_footer_message','Thank you for bean here!'),('tax_rate','0.1200');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaction_items`
--

DROP TABLE IF EXISTS `transaction_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_items` (
  `transaction_item_id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name_snapshot` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `chosen_ingredient_name_snapshot` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `variant_name_snapshot` varchar(50) DEFAULT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`transaction_item_id`) USING BTREE,
  KEY `fk_transaction_id` (`transaction_id`) USING BTREE,
  KEY `fk_product_id` (`product_id`) USING BTREE,
  CONSTRAINT `fk_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transaction_id` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaction_items`
--

LOCK TABLES `transaction_items` WRITE;
/*!40000 ALTER TABLE `transaction_items` DISABLE KEYS */;
INSERT INTO `transaction_items` (`transaction_item_id`, `transaction_id`, `product_id`, `product_name_snapshot`, `chosen_ingredient_name_snapshot`, `variant_name_snapshot`, `quantity`, `unit_price`, `subtotal`) VALUES (22,23,NULL,'Caramel Machiatto',NULL,NULL,1,50.00,50.00),(23,24,NULL,'Caramel Machiatto',NULL,NULL,1,50.00,50.00),(25,27,NULL,'Caramel Machiatto',NULL,NULL,1,50.00,50.00),(26,28,NULL,'Caramel Machiatto',NULL,NULL,1,50.00,50.00),(27,29,NULL,'Caramel Machiatto',NULL,NULL,1,50.00,50.00),(28,30,NULL,'Egg Burger',NULL,NULL,2,25.00,50.00),(30,32,NULL,'Egg Burger',NULL,NULL,1,25.00,25.00),(32,34,NULL,'kopiko',NULL,'Hot',1,16.00,16.00),(33,35,18,'French Fries',NULL,NULL,1,30.00,30.00),(34,35,19,'Caramel Macchiato',NULL,'Iced',1,59.00,59.00);
/*!40000 ALTER TABLE `transaction_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `cashier_username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `transaction_date` datetime NOT NULL,
  `transaction_total` decimal(10,2) NOT NULL,
  `transaction_status` enum('completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'completed',
  `payment_method` enum('cash','online') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `discount` decimal(10,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`transaction_id`) USING BTREE,
  KEY `fk_transactions_user` (`user_id`) USING BTREE,
  CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` (`transaction_id`, `user_id`, `cashier_username`, `transaction_date`, `transaction_total`, `transaction_status`, `payment_method`, `discount`) VALUES (23,32,'admin1','2026-08-19 23:48:07',50.00,'completed','cash',0.00),(24,32,'admin1','2026-08-20 00:04:04',40.00,'completed','cash',10.00),(27,32,'admin1','2026-08-20 00:22:39',50.00,'completed','cash',0.00),(28,33,'staff1','2026-08-20 00:32:16',50.00,'completed','cash',0.00),(29,32,'admin1','2026-08-20 00:33:41',50.00,'completed','cash',0.00),(30,32,'admin1','2026-08-20 00:39:15',50.00,'completed','cash',0.00),(32,32,'admin1','2026-08-20 00:48:20',25.00,'completed','cash',0.00),(34,32,'admin1','2026-08-20 09:48:08',16.00,'completed','cash',0.00),(35,32,'admin1','2026-08-20 15:13:45',89.00,'completed','cash',0.00);
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `role` enum('cafe owner','cafe staff') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_timestamp` timestamp NOT NULL DEFAULT (now()),
  PRIMARY KEY (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` (`user_id`, `username`, `password`, `role`, `status`, `created_timestamp`) VALUES (32,'admin1','$2y$10$UwG29gIRupUfhWQKyKV50eOLcbpxjSWPI4KAN5HTDMRan5znCFvFC','cafe owner','active','2026-08-19 15:30:21'),(33,'staff1','$2y$10$0cOttxHs/0/0YF5OlqnHVuiq9m3IH03F8A5VrrBbDsEplMToL.vR2','cafe staff','active','2026-08-19 15:31:25');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-29 22:59:58
