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
-- Current Database: `bean_there_cafe`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `bean_there_cafe` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `bean_there_cafe`;

--
-- Dumping data for table `product_category`
--

LOCK TABLES `product_category` WRITE;
/*!40000 ALTER TABLE `product_category` DISABLE KEYS */;
INSERT INTO `product_category` VALUES (11,'Appetizers'),(12,'Burgers & Sandwiches'),(13,'Rice Meals'),(17,'Seafood'),(18,'Wings & Rice'),(23,'Pasta Dishes'),(24,'Pizza'),(25,'Iced And Hot Drinks'),(27,'Add-Ons'),(28,'Ice Blended Coffee Based Drinks'),(29,'Ice Blended Cream Based Drinks');
/*!40000 ALTER TABLE `product_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `product_ingredient_items`
--

LOCK TABLES `product_ingredient_items` WRITE;
/*!40000 ALTER TABLE `product_ingredient_items` DISABLE KEYS */;
INSERT INTO `product_ingredient_items` VALUES (15,6,7,150.00,'g',0),(16,6,9,30.00,'g',0),(17,6,14,30.00,'g',0),(18,6,10,30.00,'g',0),(19,7,11,5.00,'piece',0),(20,7,3,50.00,'g',0),(21,8,16,150.00,'g',0),(22,8,8,1.00,'pair',0),(23,8,17,1.00,'piece',0),(24,8,10,15.00,'g',0),(25,8,9,15.00,'g',0),(26,8,14,15.00,'g',0),(27,8,18,20.00,'ml',0),(28,9,19,100.00,'g',0),(29,9,8,1.00,'pair',0),(30,9,20,50.00,'ml',0),(31,9,9,20.00,'g',0),(32,10,21,1.00,'pair',0),(33,10,22,100.00,'g',0),(34,10,17,1.00,'piece',0),(35,11,23,1.00,'piece',0),(36,12,25,100.00,'g',0),(38,13,24,100.00,'g',0),(40,14,26,1.00,'piece',0),(43,15,18,50.00,'ml',0),(44,15,26,1.00,'piece',0),(45,16,23,1.00,'piece',0),(46,17,36,200.00,'g',0),(47,18,37,200.00,'g',0),(48,19,43,200.00,'g',0),(55,20,18,50.00,'ml',1),(56,20,30,300.00,'g',0),(57,20,33,50.00,'ml',1),(58,20,34,50.00,'ml',1),(59,20,35,50.00,'ml',1),(60,20,32,50.00,'ml',1),(61,21,41,50.00,'g',0),(62,21,25,50.00,'g',0),(63,21,39,50.00,'g',0),(64,21,40,20.00,'g',0),(65,22,41,50.00,'g',0),(66,22,43,50.00,'g',0),(67,23,41,50.00,'g',0),(68,23,44,100.00,'g',0),(69,24,41,50.00,'g',0),(70,24,45,50.00,'g',0),(71,24,46,50.00,'g',0),(72,25,46,50.00,'g',0),(73,25,47,50.00,'g',0),(74,26,47,100.00,'g',0),(75,26,9,50.00,'g',0),(76,27,46,70.00,'g',0),(77,27,47,50.00,'g',0),(78,27,48,50.00,'g',0),(82,28,49,50.00,'g',0),(83,28,47,50.00,'g',0),(84,28,46,70.00,'g',0),(85,30,50,10.00,'g',0),(98,5,4,20.00,'g',1),(99,5,2,200.00,'g',0),(100,5,5,20.00,'g',1),(126,31,50,10.00,'g',0),(129,40,50,30.00,'g',0),(130,40,60,10.00,'ml',0),(131,40,51,50.00,'ml',0),(132,41,50,30.00,'g',0),(133,41,53,30.00,'ml',0),(134,41,51,50.00,'ml',0),(135,42,50,30.00,'g',0),(136,42,53,20.00,'ml',0),(137,42,60,20.00,'ml',0),(138,42,51,50.00,'ml',0),(139,43,50,30.00,'ml',0),(140,43,53,20.00,'ml',0),(141,43,51,50.00,'ml',0),(142,43,61,3.00,'piece',0),(143,44,50,30.00,'g',0),(144,44,56,20.00,'ml',0),(145,44,51,50.00,'ml',0),(146,44,59,50.00,'g',0),(147,45,51,200.00,'ml',0),(148,45,60,20.00,'ml',0),(149,45,58,10.00,'ml',0),(150,46,51,250.00,'ml',0),(151,46,53,50.00,'ml',0),(152,46,58,20.00,'ml',0),(153,47,51,250.00,'ml',0),(154,47,53,20.00,'ml',0),(155,47,60,20.00,'ml',0),(156,47,58,20.00,'ml',0),(157,48,51,250.00,'ml',0),(158,48,58,20.00,'ml',0),(159,48,61,5.00,'piece',0),(160,48,60,30.00,'ml',0),(161,49,56,50.00,'ml',0),(162,49,60,30.00,'ml',0),(163,49,59,20.00,'g',0),(164,50,57,5.00,'g',0),(165,50,51,250.00,'ml',0),(166,50,58,20.00,'ml',0),(167,33,50,15.00,'g',0),(168,33,51,150.00,'ml',0),(169,32,50,10.00,'g',0),(170,32,51,60.00,'ml',0),(171,35,55,10.00,'ml',0),(172,35,50,15.00,'g',0),(173,35,51,150.00,'ml',0),(174,35,54,10.00,'ml',0),(175,37,60,20.00,'ml',0),(176,37,53,20.00,'ml',0),(177,37,50,20.00,'g',0),(178,37,51,200.00,'ml',0),(179,39,59,10.00,'g',0),(180,39,53,40.00,'ml',0),(181,39,51,220.00,'ml',0),(182,38,57,5.00,'g',0),(183,38,51,70.00,'ml',0),(184,38,58,15.00,'ml',0),(185,34,53,15.00,'ml',0),(186,34,50,15.00,'g',0),(187,34,51,20.00,'ml',0),(188,36,50,20.00,'g',0),(189,36,51,100.00,'ml',0),(190,36,56,25.00,'ml',0);
/*!40000 ALTER TABLE `product_ingredient_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `product_ingredients`
--

LOCK TABLES `product_ingredients` WRITE;
/*!40000 ALTER TABLE `product_ingredients` DISABLE KEYS */;
INSERT INTO `product_ingredients` VALUES (2,'French Fries',2.80,3.00,'kg','N/A','N/A'),(3,'GROUND PORK',50.00,100.00,'g',NULL,NULL),(4,'CHEESE POWDER',4860.00,4940.00,'GRAMS',NULL,NULL),(5,'SOUR CREAM',1760.00,1960.00,'GRAMS',NULL,NULL),(6,'CHEESE SAUCE',5000.00,5000.00,'GRAMS',NULL,NULL),(7,'TORTILA CHIPS',4850.00,5000.00,'GRAMS',NULL,NULL),(8,'burger bun',100.00,100.00,'pair','123 BAKERY SHOP','09123456789'),(9,'Tomato',1.97,2.00,'kg','N/A',NULL),(10,'cucumber',1.97,2.00,'kg','N/A','N/A'),(11,'long green chili',95.00,100.00,'piece','N/A','N/A'),(14,'onions',1.97,2.00,'kg','N/A','N/A'),(15,'quickmelt cheese',100.00,100.00,'strip','N/A','N/A'),(16,'beef patty',5.00,5.00,'kg','N/A','N/A'),(17,'SLICED CHEESE',100.00,100.00,'piece','123 MERCHANDISE STORE','0945789123'),(18,'barbeque sauce',3.00,3.00,'l','123 MERCHANDISE STORE','09123456789'),(19,'chicken breast',5.00,5.00,'kg','123 MERCHANDISE STORE','09123456789'),(20,'honey mustard',3.00,3.00,'l','123 MERCHANDISE STORE','09123456789'),(21,'white bread',200.00,200.00,'pair','123 MERCHANDISE STORE','09123456789'),(22,'chicken spread',5.00,5.00,'kg','123 MERCHANDISE STORE','09123456789'),(23,'liempo',30.00,30.00,'piece','123 MERCHANDISE STORE','09123456789'),(24,'fish fillet',20.00,20.00,'piece','123 MERCHANDISE STORE','09123456789'),(25,'chicken finger strips',4.00,4.00,'kg','456 MERCHANDISE STORE','09222555100'),(26,'PORK CHOP',30.00,30.00,'piece','456 MERCHANDISE STORE','09222555100'),(27,'PORK KASIM',3.00,3.00,'kg','456 MERCHANDISE STORE','09222555100'),(28,'teriyaki sauce',3.00,3.00,'l','456 MERCHANDISE STORE','09222555100'),(29,'bell pepper',2.00,2.00,'kg','456 MERCHANDISE STORE','09222555100'),(30,'chicken wings',10.00,10.00,'kg','456 MERCHANDISE STORE','09123456789'),(31,'beef tapa',3.00,3.00,'kg','456 MERCHANDISE STORE','09222555100'),(32,'hot and spicy sauce',2.00,2.00,'l','789 MERCHANDISE STORE','09123456789'),(33,'GARLIC BUTTER SAUCE',2.00,2.00,'l','789 MERCHANDISE STORE','09123456789'),(34,'GARLIC PARMESAN SAUCE',2.00,2.00,'l','789 MERCHANDISE STORE','09123456789'),(35,'HONEY SOY GARLIC',2.00,2.00,'l','789 MERCHANDISE STORE','09123456789'),(36,'calamares',3.00,3.00,'kg','789 MERCHANDISE STORE','09123456789'),(37,'shrimp tempura',3.00,3.00,'kg','789 MERCHANDISE STORE','09123456789'),(38,'chicken in a basket',100.00,100.00,'piece','789 MERCHANDISE STORE','09123456789'),(39,'homemade pesto paste',2.00,2.00,'kg',NULL,NULL),(40,'parmesan cheese',2.00,2.00,'kg','789 MERCHANDISE STORE','09123456789'),(41,'pasta',3.00,3.00,'kg','123 MERCHANDISE STORE','09222555100'),(42,'olive oil',2.00,2.00,'l','789 MERCHANDISE STORE','09123456789'),(43,'shrimp',3.00,3.00,'kg','789 MERCHANDISE STORE','09123456789'),(44,'clams',3.00,3.00,'kg','789 MERCHANDISE STORE','09222555100'),(45,'tuna',3.00,3.00,'kg','789 MERCHANDISE STORE','09123456789'),(46,'tomato sauce',3.00,3.00,'kg','789 MERCHANDISE STORE','09123456789'),(47,'mozzarella cheese',2.00,2.00,'kg','789 MERCHANDISE STORE','09123456789'),(48,'pepperoni',3.00,3.00,'kg','789 MERCHANDISE STORE','09123456789'),(49,'ham',3.00,3.00,'kg','789 MERCHANDISE STORE','09123456789'),(50,'coffee beans',10.00,10.00,'kg','YIN MERCHANDISE STORE','09456789123'),(51,'milk',10.00,10.00,'l','YIN MERCHANDISE STORE','09456789123'),(52,'whipped cream',10.00,10.00,'l','YIN MERCHANDISE STORE','09456789123'),(53,'chocolate sauce',5.00,5.00,'l','YIN MERCHANDISE STORE','09456789123'),(54,'vanilla syrup',5.00,5.00,'l','YIN MERCHANDISE STORE','09456789123'),(55,'caramel drizzle',5.00,5.00,'l','YIN MERCHANDISE STORE','09456789123'),(56,'white chocolate sauce',10.00,10.00,'l','YIN MERCHANDISE STORE','09456789123'),(57,'matcha powder',10.00,10.00,'kg','YIN MERCHANDISE STORE','09456789123'),(58,'syrup',10.00,10.00,'l','YIN MERCHANDISE STORE','09456789123'),(59,'chocolate powder',10.00,10.00,'kg','YIN MERCHANDISE STORE','09456789123'),(60,'caramel syrup',10.00,10.00,'l','YIN MERCHANDISE STORE','09456789123'),(61,'oreo cookies',100.00,100.00,'piece','YIN MERCHANDISE STORE','09456789123');
/*!40000 ALTER TABLE `product_ingredients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `product_supplier`
--

LOCK TABLES `product_supplier` WRITE;
/*!40000 ALTER TABLE `product_supplier` DISABLE KEYS */;
INSERT INTO `product_supplier` VALUES (1,'N/A','N/A'),(2,'In-house','—');
/*!40000 ALTER TABLE `product_supplier` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `product_variants`
--

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
INSERT INTO `product_variants` VALUES (9,31,'Iced',90.00,0),(10,31,'Hot',85.00,1),(11,33,'HOT',120.00,0),(12,33,'Iced',120.00,1),(13,32,'Iced',110.00,0),(14,32,'Hot',110.00,1),(15,35,'Iced',120.00,0),(16,35,'Hot',120.00,1),(17,37,'Iced',140.00,0),(18,37,'Hot',140.00,1),(19,39,'Iced',100.00,0),(20,39,'Hot',100.00,1),(21,34,'Iced',130.00,0),(22,34,'Hot',130.00,1),(23,36,'Iced',135.00,0),(24,36,'Hot',135.00,1);
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (5,'French Fries',11,0,0,40.00,100.00,2,'made_to_order','uploads/products/prod_6a90621862c239.85208606.webp'),(6,'Cheesy Nachos',11,0,0,70.00,170.00,2,'made_to_order','uploads/products/prod_6a9067c3798c19.71675437.jpg'),(7,'Dynamite (5 PCS)',11,0,0,40.00,130.00,2,'made_to_order','uploads/products/prod_6a9068737d1971.06482324.jpg'),(8,'Beef Burger',12,0,0,80.00,180.00,2,'made_to_order','uploads/products/prod_6a906c9f083b75.91099766.jpg'),(9,'Chicken Burger',12,0,0,80.00,160.00,2,'made_to_order','uploads/products/prod_6a90739b924715.60433921.jpg'),(10,'Chicken N\' Cheese Sandwich',12,0,0,50.00,120.00,2,'made_to_order','uploads/products/prod_6a907e19459552.93368582.jpg'),(11,'Grilled Liempo',13,0,0,50.00,130.00,2,'made_to_order','uploads/products/prod_6a918bab73d892.75906162.webp'),(12,'Chicken Finger Strips',13,0,0,50.00,115.00,2,'made_to_order','uploads/products/prod_6a918c5f693c55.78884748.jpg'),(13,'Breaded Fish Fillet',13,0,0,50.00,110.00,2,'made_to_order','uploads/products/prod_6a918ccfa0daf6.82693399.jpg'),(14,'Tonkatsu Strips',13,0,0,60.00,125.00,2,'made_to_order','uploads/products/prod_6a918d3f4d7736.86809998.jpg'),(15,'Pork BBQ Strips',13,0,0,50.00,130.00,2,'made_to_order','uploads/products/prod_6a918dfc805925.00557230.webp'),(16,'Lechon Kawali',13,0,0,70.00,160.00,2,'made_to_order','uploads/products/prod_6a918e6392f751.48682971.jpg'),(17,'Calamares',17,0,0,100.00,250.00,2,'made_to_order','uploads/products/prod_6a918f319ca601.84594842.webp'),(18,'Shrimp Tempura',17,0,0,100.00,250.00,2,'made_to_order','uploads/products/prod_6a918f6a0b3a80.34902130.jpg'),(19,'Buttered Shrimp',17,0,0,120.00,260.00,2,'made_to_order','uploads/products/prod_6a91909bc24217.17226617.jpg'),(20,'chicken wings w/ Rice',18,0,0,70.00,180.00,2,'made_to_order','uploads/products/prod_6a9191fadb4822.36603857.jpg'),(21,'Chicken Pesto',23,0,0,70.00,170.00,2,'made_to_order','uploads/products/prod_6a91939f25da78.28175786.jpg'),(22,'Aglio Olio e Gamberetti',23,0,0,90.00,190.00,2,'made_to_order','uploads/products/prod_6a9193eede8057.26351116.jpg'),(23,'Spaghetti Vongole',23,0,0,90.00,190.00,2,'made_to_order','uploads/products/prod_6a91944b4df776.23162183.jpg'),(24,'Cheezy Spaghetti',23,0,0,70.00,170.00,2,'made_to_order','uploads/products/prod_6a919493d92762.32827099.jpg'),(25,'Pizza Margherita',24,0,0,80.00,185.00,2,'made_to_order','uploads/products/prod_6a919557ec5a15.68734480.jpg'),(26,'Bruschetta Pizza',24,0,0,110.00,220.00,2,'made_to_order','uploads/products/prod_6a91959b65aa68.01056984.jpg'),(27,'Pepperoni Pizza',24,0,0,110.00,220.00,2,'made_to_order','uploads/products/prod_6a9195e7669dd7.60687044.jpg'),(28,'Hawaiian Pizza',24,0,0,110.00,220.00,2,'made_to_order','uploads/products/prod_6a91968612efa4.58278452.jpg'),(29,'Friend Chicken w/ Rice',18,0,0,100.00,220.00,2,'made_to_order','uploads/products/prod_6a91981fba5ca4.04167930.jpg'),(30,'Espresso',25,0,0,20.00,55.00,2,'made_to_order','uploads/products/prod_6a919ce767fc74.03782821.jpg'),(31,'Americano',25,0,0,40.00,90.00,2,'made_to_order','uploads/products/prod_6a919d9b69e234.62869661.jpg'),(32,'Cappucino',25,0,0,50.00,110.00,2,'made_to_order','uploads/products/prod_6a919df40bd5b6.89756712.jpg'),(33,'caffe latte',25,0,0,50.00,120.00,2,'made_to_order','uploads/products/prod_6a919e45671396.78418544.jpg'),(34,'Mocha',25,0,0,70.00,130.00,2,'made_to_order','uploads/products/prod_6a919eb6308bb5.32050981.jpg'),(35,'Caramel Macchiato',25,0,0,50.00,120.00,2,'made_to_order','uploads/products/prod_6a919f3fab43e3.81544388.jpg'),(36,'White Chocolate',25,0,0,50.00,135.00,2,'made_to_order','uploads/products/prod_6a91a2b5a8d005.51350964.jpg'),(37,'Caramel Mocha',25,0,0,70.00,140.00,2,'made_to_order','uploads/products/prod_6a91a3cbe4e481.87031022.jpg'),(38,'Matcha Latte',25,0,0,70.00,150.00,2,'made_to_order','uploads/products/prod_6a91a5343b8d56.19405069.jpg'),(39,'Chocolate Drink',25,0,0,40.00,100.00,2,'made_to_order','uploads/products/prod_6a91a5ca175f14.58474945.jpg'),(40,'Caramel Coffee',28,0,0,70.00,150.00,2,'made_to_order','uploads/products/prod_6a91a7037b8136.00978349.jpg'),(41,'Chocolate Coffee',28,0,0,70.00,150.00,2,'made_to_order','uploads/products/prod_6a91a74ba0c862.64023474.jpg'),(42,'Caramel Mocha Coffee',28,0,0,70.00,150.00,2,'made_to_order','uploads/products/prod_6a91a7a9ce5987.88801803.jpg'),(43,'Choco Oreo Mocha',28,0,0,70.00,150.00,2,'made_to_order','uploads/products/prod_6a91a820ccb1a2.39678334.jpg'),(44,'White Chocolate Coffee',28,0,0,70.00,150.00,2,'made_to_order','uploads/products/prod_6a91a877c7d135.30538547.jpg'),(45,'Caramel Cream',29,0,0,70.00,180.00,2,'made_to_order','uploads/products/prod_6a91aa055e0c12.56553188.jpg'),(46,'Chocolate Cream',29,0,0,80.00,180.00,2,'made_to_order','uploads/products/prod_6a91aa54310f69.51447009.jpg'),(47,'Caramel Mocha Cream',29,0,0,80.00,180.00,2,'made_to_order','uploads/products/prod_6a91aab187d9e0.38599608.jpg'),(48,'Cookies and Cream',29,0,0,80.00,180.00,2,'made_to_order','uploads/products/prod_6a91ab0b453013.15638620.jpg'),(49,'White Chocolate Cream',29,0,0,80.00,180.00,2,'made_to_order','uploads/products/prod_6a91ab5526d703.66932566.jpg'),(50,'Blended Matcha',29,0,0,80.00,180.00,2,'made_to_order','uploads/products/prod_6a91ab98a505b9.77267129.jpg');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES ('cafe_address',''),('cafe_contact',''),('cafe_name','Bean There Café'),('critical_stock_threshold','80'),('discount_rate','0.2000'),('ewallet_qr_image','uploads/settings/ewallet_6a79cc989d85f2.03344994.webp'),('low_stock_threshold','90'),('receipt_footer_message','Thank you for bean here!'),('tax_rate','0.1200');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `transaction_items`
--

LOCK TABLES `transaction_items` WRITE;
/*!40000 ALTER TABLE `transaction_items` DISABLE KEYS */;
INSERT INTO `transaction_items` VALUES (1,1,NULL,'TUBOG-TUBOG',NULL,NULL,1,20.00,20.00),(2,2,NULL,'FRENCH FRIES',NULL,NULL,2,100.00,200.00),(3,3,NULL,'french fries','CHEESE POWDER',NULL,1,100.00,100.00),(4,26,NULL,'French Fries','CHEESE POWDER',NULL,1,100.00,100.00),(5,27,NULL,'French Fries','SOUR CREAM',NULL,1,100.00,100.00),(6,28,NULL,'French Fries','CHEESE POWDER',NULL,1,100.00,100.00),(7,29,NULL,'French Fries','SOUR CREAM',NULL,1,100.00,100.00),(8,30,NULL,'French Fries','SOUR CREAM',NULL,1,100.00,100.00),(9,31,NULL,'French Fries','SOUR CREAM',NULL,1,100.00,100.00),(10,32,NULL,'French Fries','SOUR CREAM',NULL,1,100.00,100.00),(11,33,NULL,'French Fries','CHEESE POWDER',NULL,1,100.00,100.00),(12,34,NULL,'French Fries','CHEESE POWDER',NULL,1,100.00,100.00),(13,35,NULL,'French Fries','SOUR CREAM',NULL,4,100.00,400.00),(14,36,6,'Cheesy Nachos',NULL,NULL,1,170.00,170.00),(15,36,7,'Dynamite (5 PCS)',NULL,NULL,1,130.00,130.00),(16,36,5,'French Fries','SOUR CREAM',NULL,1,100.00,100.00);
/*!40000 ALTER TABLE `transaction_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (1,1,'owner','2026-08-10 21:05:40',20.00,'completed','online',0.00,NULL),(2,1,'owner','2026-08-11 14:35:40',160.00,'completed','cash',40.00,NULL),(3,1,'owner','2026-08-11 15:10:55',100.00,'completed','cash',0.00,NULL),(26,1,'owner','2026-08-22 11:39:18',100.00,'completed','cash',0.00,NULL),(27,1,'owner','2026-08-27 15:13:33',100.00,'completed','cash',0.00,NULL),(28,1,'owner','2026-08-27 15:14:19',100.00,'completed','cash',0.00,NULL),(29,1,'owner','2026-08-27 15:15:44',100.00,'completed','cash',0.00,NULL),(30,1,'owner','2026-08-27 15:33:11',100.00,'completed','cash',0.00,NULL),(31,1,'owner','2026-08-27 15:58:20',80.00,'completed','online',20.00,'Gcash payment'),(32,1,'owner','2026-08-27 16:01:27',80.00,'completed','online',20.00,'bank transfer'),(33,1,'owner','2026-08-27 16:03:19',80.00,'completed','cash',20.00,NULL),(34,1,'owner','2026-08-27 21:18:35',100.00,'completed','cash',0.00,NULL),(35,1,'owner','2026-08-27 21:46:31',400.00,'completed','online',0.00,'Gcash payment'),(36,1,'owner','2026-08-29 00:01:15',400.00,'completed','cash',0.00,NULL);
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'owner','password','cafe owner','active','2026-08-04 14:53:42'),(2,'staff','password','cafe staff','active','2026-08-04 14:56:32');
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

-- Dump completed on 2026-08-29  0:49:51
