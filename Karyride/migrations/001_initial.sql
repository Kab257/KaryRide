-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 29, 2026 at 07:28 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `karyride`
--

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_user_id` int(10) UNSIGNED NOT NULL,
  `company_name` varchar(120) NOT NULL,
  `registration_number` varchar(100) DEFAULT NULL,
  `phone` varchar(25) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `province` varchar(80) NOT NULL DEFAULT 'Bujumbura Mairie',
  `commune` varchar(80) NOT NULL,
  `zone` varchar(80) DEFAULT NULL,
  `quartier` varchar(80) DEFAULT NULL,
  `address_details` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `status` enum('pending','approved','rejected','suspended','inactive') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `accepts_advance_booking` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_client_restrictions`
--

CREATE TABLE `company_client_restrictions` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `reason` text NOT NULL,
  `suspended_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `lifted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_staff`
--

CREATE TABLE `company_staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `job_title` varchar(100) NOT NULL DEFAULT 'Agent',
  `permissions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions_json`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dispatch_rejections`
--

CREATE TABLE `dispatch_rejections` (
  `id` int(10) UNSIGNED NOT NULL,
  `ride_id` int(10) UNSIGNED NOT NULL,
  `driver_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `rejected_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `current_vehicle_id` int(10) UNSIGNED DEFAULT NULL,
  `driving_license_number` varchar(60) NOT NULL,
  `national_id_number` varchar(60) DEFAULT NULL,
  `driver_status` enum('available','busy','offline') NOT NULL DEFAULT 'offline',
  `current_latitude` decimal(10,8) DEFAULT NULL,
  `current_longitude` decimal(11,8) DEFAULT NULL,
  `last_location_update` datetime DEFAULT NULL,
  `is_suspended` tinyint(1) NOT NULL DEFAULT 0,
  `suspension_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `ride_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `payment_reference` varchar(60) NOT NULL,
  `qr_code_token` varchar(255) NOT NULL,
  `amount_bif` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','ecocash_manual','lumicash_manual','card','mobile_money_pending') NOT NULL DEFAULT 'cash',
  `payment_status` enum('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  `paid_at` datetime DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pricing_plans`
--

CREATE TABLE `pricing_plans` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `base_fare` decimal(12,2) NOT NULL DEFAULT 0.00,
  `price_per_km` decimal(12,2) NOT NULL DEFAULT 0.00,
  `min_fare` decimal(12,2) NOT NULL DEFAULT 0.00,
  `advance_booking_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `promotions`
--

CREATE TABLE `promotions` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `promo_code` varchar(40) NOT NULL,
  `promo_title` varchar(120) NOT NULL,
  `promo_type` enum('percentage','fixed_amount','loyalty_rides') NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `min_rides_required` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `valid_from` date NOT NULL,
  `valid_until` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(10) UNSIGNED NOT NULL,
  `ride_id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `driver_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `driver_rating` tinyint(3) UNSIGNED NOT NULL,
  `driver_comment` text DEFAULT NULL,
  `company_rating` tinyint(3) UNSIGNED NOT NULL,
  `company_comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `rides`
--

CREATE TABLE `rides` (
  `id` int(10) UNSIGNED NOT NULL,
  `ride_reference` varchar(40) NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `driver_id` int(10) UNSIGNED DEFAULT NULL,
  `vehicle_id` int(10) UNSIGNED DEFAULT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `promo_id` int(10) UNSIGNED DEFAULT NULL,
  `booking_type` enum('now','advance') NOT NULL DEFAULT 'now',
  `scheduled_datetime` datetime DEFAULT NULL,
  `pickup_address` varchar(255) NOT NULL,
  `pickup_latitude` decimal(10,8) DEFAULT NULL,
  `pickup_longitude` decimal(11,8) DEFAULT NULL,
  `destination_address` varchar(255) NOT NULL,
  `destination_latitude` decimal(10,8) DEFAULT NULL,
  `destination_longitude` decimal(11,8) DEFAULT NULL,
  `estimated_distance_km` decimal(8,2) NOT NULL DEFAULT 0.00,
  `actual_distance_km` decimal(8,2) DEFAULT NULL,
  `estimated_duration_min` int(10) UNSIGNED DEFAULT NULL,
  `actual_duration_min` int(10) UNSIGNED DEFAULT NULL,
  `base_fare` decimal(12,2) NOT NULL DEFAULT 0.00,
  `distance_fare` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','accepted','driver_arrived','in_progress','destination_reached','completed','cancelled') NOT NULL DEFAULT 'pending',
  `cancelled_by` enum('client','driver','company','system') DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `accepted_at` datetime DEFAULT NULL,
  `arrived_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `destination_reached_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `uploaded_documents`
--

CREATE TABLE `uploaded_documents` (
  `id` int(10) UNSIGNED NOT NULL,
  `entity_type` enum('company','driver','vehicle') NOT NULL,
  `entity_id` int(10) UNSIGNED NOT NULL,
  `document_type` varchar(60) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `verified_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `verification_reason` text DEFAULT NULL,
  `verified_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `role` enum('superadmin','company_owner','company_manager','company_employee','driver','client') NOT NULL DEFAULT 'client',
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(25) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `preferred_language` enum('rn','en') NOT NULL DEFAULT 'rn',
  `avatar_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_banned_globally` tinyint(1) NOT NULL DEFAULT 0,
  `ban_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role`, `first_name`, `last_name`, `email`, `phone`, `password_hash`, `preferred_language`, `avatar_url`, `is_active`, `is_banned_globally`, `ban_reason`, `created_at`, `updated_at`) VALUES
(1, 'client', 'Client', 'Kary', 'client@karyride.com', '+25768661170', '$2y$10$nV27jd56SZEPdC68plheaeq5EnfpJuFqjy2PpNF/y7.EpBVbbolma', 'en', NULL, 1, 0, NULL, '2026-08-29 01:22:15', '2026-08-29 01:22:15');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `plate_number` varchar(30) NOT NULL,
  `brand` varchar(60) NOT NULL,
  `model` varchar(60) NOT NULL,
  `color` varchar(40) DEFAULT NULL,
  `manufacture_year` smallint(5) UNSIGNED DEFAULT NULL,
  `seating_capacity` tinyint(3) UNSIGNED NOT NULL DEFAULT 4,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_categories`
--

CREATE TABLE `vehicle_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED DEFAULT NULL,
  `category_name` varchar(60) NOT NULL,
  `vehicle_type` enum('car','motorcycle','minibus','truck') NOT NULL DEFAULT 'car',
  `icon_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_registration` (`registration_number`),
  ADD KEY `idx_companies_owner` (`owner_user_id`),
  ADD KEY `idx_companies_status` (`status`),
  ADD KEY `idx_companies_location` (`province`,`commune`);

--
-- Indexes for table `company_client_restrictions`
--
ALTER TABLE `company_client_restrictions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_client_restriction` (`company_id`,`client_id`),
  ADD KEY `fk_restrictions_created_by` (`created_by_user_id`),
  ADD KEY `idx_restrictions_company_active` (`company_id`,`is_active`),
  ADD KEY `idx_restrictions_client` (`client_id`);

--
-- Indexes for table `company_staff`
--
ALTER TABLE `company_staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_user_staff` (`company_id`,`user_id`),
  ADD KEY `fk_staff_user` (`user_id`),
  ADD KEY `idx_staff_company_active` (`company_id`,`is_active`);

--
-- Indexes for table `dispatch_rejections`
--
ALTER TABLE `dispatch_rejections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dispatch_rejections_ride` (`ride_id`),
  ADD KEY `idx_dispatch_rejections_driver` (`driver_id`),
  ADD KEY `idx_dispatch_rejections_company` (`company_id`),
  ADD KEY `idx_dispatch_rejections_time` (`rejected_at`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_driver_user` (`user_id`),
  ADD UNIQUE KEY `uk_driver_license` (`driving_license_number`),
  ADD UNIQUE KEY `uk_driver_company_id` (`id`,`company_id`),
  ADD KEY `fk_drivers_vehicle_same_company` (`current_vehicle_id`,`company_id`),
  ADD KEY `idx_drivers_company` (`company_id`),
  ADD KEY `idx_drivers_company_status` (`company_id`,`driver_status`),
  ADD KEY `idx_drivers_gps` (`current_latitude`,`current_longitude`),
  ADD KEY `idx_drivers_location_update` (`last_location_update`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_invoice_ride` (`ride_id`),
  ADD UNIQUE KEY `uk_invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `uk_payment_reference` (`payment_reference`),
  ADD UNIQUE KEY `uk_qr_code_token` (`qr_code_token`),
  ADD KEY `idx_invoices_payment_status` (`payment_status`),
  ADD KEY `idx_invoices_payment_method` (`payment_method`);

--
-- Indexes for table `pricing_plans`
--
ALTER TABLE `pricing_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_category_pricing` (`company_id`,`category_id`),
  ADD KEY `fk_pricing_category` (`category_id`),
  ADD KEY `idx_pricing_company_active` (`company_id`,`is_active`);

--
-- Indexes for table `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_promo_code` (`company_id`,`promo_code`),
  ADD KEY `idx_promotions_company_active` (`company_id`,`is_active`),
  ADD KEY `idx_promotions_validity` (`valid_from`,`valid_until`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_review_ride` (`ride_id`),
  ADD KEY `idx_reviews_driver` (`driver_id`),
  ADD KEY `idx_reviews_company` (`company_id`),
  ADD KEY `idx_reviews_client` (`client_id`);

--
-- Indexes for table `rides`
--
ALTER TABLE `rides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_ride_reference` (`ride_reference`),
  ADD KEY `fk_rides_driver_same_company` (`driver_id`,`company_id`),
  ADD KEY `fk_rides_vehicle_same_company` (`vehicle_id`,`company_id`),
  ADD KEY `fk_rides_promotion` (`promo_id`),
  ADD KEY `idx_rides_client` (`client_id`),
  ADD KEY `idx_rides_company` (`company_id`),
  ADD KEY `idx_rides_company_status` (`company_id`,`status`),
  ADD KEY `idx_rides_driver_status` (`driver_id`,`status`),
  ADD KEY `idx_rides_vehicle` (`vehicle_id`),
  ADD KEY `idx_rides_category` (`category_id`),
  ADD KEY `idx_rides_requested` (`requested_at`),
  ADD KEY `idx_rides_scheduled` (`scheduled_datetime`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `uploaded_documents`
--
ALTER TABLE `uploaded_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_documents_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_documents_status` (`verified_status`),
  ADD KEY `idx_documents_verified_by` (`verified_by_user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_users_phone` (`phone`),
  ADD UNIQUE KEY `uk_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_active` (`is_active`),
  ADD KEY `idx_users_global_ban` (`is_banned_globally`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_vehicle_plate` (`plate_number`),
  ADD UNIQUE KEY `uk_vehicle_company_id` (`id`,`company_id`),
  ADD KEY `idx_vehicles_company` (`company_id`),
  ADD KEY `idx_vehicles_company_active` (`company_id`,`is_active`),
  ADD KEY `idx_vehicles_category` (`category_id`);

--
-- Indexes for table `vehicle_categories`
--
ALTER TABLE `vehicle_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_categories_company` (`company_id`),
  ADD KEY `idx_categories_type` (`vehicle_type`),
  ADD KEY `idx_categories_active` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_client_restrictions`
--
ALTER TABLE `company_client_restrictions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_staff`
--
ALTER TABLE `company_staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dispatch_rejections`
--
ALTER TABLE `dispatch_rejections`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pricing_plans`
--
ALTER TABLE `pricing_plans`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rides`
--
ALTER TABLE `rides`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `uploaded_documents`
--
ALTER TABLE `uploaded_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicle_categories`
--
ALTER TABLE `vehicle_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `companies`
--
ALTER TABLE `companies`
  ADD CONSTRAINT `fk_companies_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `company_client_restrictions`
--
ALTER TABLE `company_client_restrictions`
  ADD CONSTRAINT `fk_restrictions_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_restrictions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_restrictions_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `company_staff`
--
ALTER TABLE `company_staff`
  ADD CONSTRAINT `fk_staff_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `dispatch_rejections`
--
ALTER TABLE `dispatch_rejections`
  ADD CONSTRAINT `fk_dispatch_rejections_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_dispatch_rejections_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_dispatch_rejections_ride` FOREIGN KEY (`ride_id`) REFERENCES `rides` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `drivers`
--
ALTER TABLE `drivers`
  ADD CONSTRAINT `fk_drivers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_drivers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_drivers_vehicle_same_company` FOREIGN KEY (`current_vehicle_id`,`company_id`) REFERENCES `vehicles` (`id`, `company_id`) ON UPDATE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_ride` FOREIGN KEY (`ride_id`) REFERENCES `rides` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `pricing_plans`
--
ALTER TABLE `pricing_plans`
  ADD CONSTRAINT `fk_pricing_category` FOREIGN KEY (`category_id`) REFERENCES `vehicle_categories` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pricing_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `promotions`
--
ALTER TABLE `promotions`
  ADD CONSTRAINT `fk_promotions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `fk_reviews_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reviews_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reviews_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reviews_ride` FOREIGN KEY (`ride_id`) REFERENCES `rides` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `rides`
--
ALTER TABLE `rides`
  ADD CONSTRAINT `fk_rides_category` FOREIGN KEY (`category_id`) REFERENCES `vehicle_categories` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rides_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rides_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rides_driver_same_company` FOREIGN KEY (`driver_id`,`company_id`) REFERENCES `drivers` (`id`, `company_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rides_promotion` FOREIGN KEY (`promo_id`) REFERENCES `promotions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rides_vehicle_same_company` FOREIGN KEY (`vehicle_id`,`company_id`) REFERENCES `vehicles` (`id`, `company_id`) ON UPDATE CASCADE;

--
-- Constraints for table `uploaded_documents`
--
ALTER TABLE `uploaded_documents`
  ADD CONSTRAINT `fk_documents_verified_by` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicles_category` FOREIGN KEY (`category_id`) REFERENCES `vehicle_categories` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_vehicles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `vehicle_categories`
--
ALTER TABLE `vehicle_categories`
  ADD CONSTRAINT `fk_categories_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
