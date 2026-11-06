-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql303.infinityfree.com
-- Generation Time: Oct 31, 2026 at 07:59 PM
-- Server version: 11.4.13-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_42800263_arnis_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `achievements`
--

CREATE TABLE `achievements` (
  `achievement_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `achievement_type` enum('Gold','Silver','Bronze','Recognition') NOT NULL,
  `level` enum('School','City','Regional','National') NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `achieved_at` date NOT NULL,
  `is_voided` tinyint(1) DEFAULT 0,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `achievements`
--

INSERT INTO `achievements` (`achievement_id`, `member_id`, `event_id`, `achievement_type`, `level`, `notes`, `achieved_at`, `is_voided`, `voided_at`, `voided_by`, `void_reason`, `created_by`, `created_at`) VALUES
(1, 4, 4, 'Gold', 'City', 'Won Youth Combat Division title', '2026-09-21', 0, NULL, NULL, NULL, 1, '2026-09-25 10:00:00'),
(2, 6, 4, 'Bronze', 'City', 'Third place - Youth Forms', '2026-09-21', 0, NULL, NULL, NULL, 1, '2026-09-25 10:05:00'),
(3, 12, 4, 'Gold', 'City', 'Senior Men Division champion', '2026-09-21', 0, NULL, NULL, NULL, 1, '2026-09-25 10:10:00'),
(4, 5, 2, 'Recognition', 'School', 'Lead performer at school demo night', '2026-10-05', 0, NULL, NULL, NULL, 2, '2026-10-05 20:30:00'),
(5, 8, 4, 'Silver', 'Regional', 'Runner-up in regional exhibition match', '2026-09-30', 0, NULL, NULL, NULL, 2, '2026-10-01 09:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `training_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `status` enum('Present','Late','Absent') NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `recorded_at` datetime DEFAULT current_timestamp(),
  `is_voided` tinyint(1) DEFAULT 0,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`attendance_id`, `training_id`, `member_id`, `status`, `remarks`, `recorded_by`, `recorded_at`, `is_voided`, `voided_at`, `voided_by`, `void_reason`, `updated_by`, `updated_at`) VALUES
(1, 3, 4, 'Present', NULL, 2, '2026-09-28 15:10:00', 0, NULL, NULL, NULL, NULL, '2026-09-28 15:10:00'),
(2, 3, 5, 'Present', NULL, 2, '2026-09-28 15:10:00', 0, NULL, NULL, NULL, NULL, '2026-09-28 15:10:00'),
(3, 3, 6, 'Late', 'Arrived 20 minutes late', 2, '2026-09-28 15:25:00', 0, NULL, NULL, NULL, NULL, '2026-09-28 15:25:00'),
(4, 3, 7, 'Present', NULL, 2, '2026-09-28 15:10:00', 0, NULL, NULL, NULL, NULL, '2026-09-28 15:10:00'),
(5, 3, 8, 'Present', NULL, 2, '2026-09-28 15:10:00', 0, NULL, NULL, NULL, NULL, '2026-09-28 15:10:00'),
(6, 3, 11, 'Absent', NULL, 2, '2026-09-28 18:00:00', 0, NULL, NULL, NULL, NULL, '2026-09-28 18:00:00'),
(7, 4, 5, 'Present', NULL, 2, '2026-10-03 08:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-03 08:05:00'),
(8, 4, 6, 'Present', NULL, 2, '2026-10-03 08:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-03 08:05:00'),
(9, 4, 7, 'Present', NULL, 2, '2026-10-03 08:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-03 08:05:00'),
(10, 4, 10, 'Present', NULL, 2, '2026-10-03 08:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-03 08:05:00'),
(11, 4, 11, 'Present', NULL, 2, '2026-10-03 08:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-03 08:05:00'),
(12, 4, 4, 'Absent', NULL, 2, '2026-10-03 10:00:00', 0, NULL, NULL, NULL, NULL, '2026-10-03 10:00:00'),
(13, 6, 4, 'Present', NULL, 1, '2026-10-06 15:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-06 15:05:00'),
(14, 6, 8, 'Present', NULL, 1, '2026-10-06 15:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-06 15:05:00'),
(15, 6, 12, 'Present', NULL, 1, '2026-10-06 15:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-06 15:05:00'),
(16, 6, 5, 'Late', NULL, 1, '2026-10-06 15:22:00', 0, NULL, NULL, NULL, NULL, '2026-10-06 15:22:00'),
(17, 7, 4, 'Present', 'Self check-in via face + GPS', 4, '2026-10-10 15:02:00', 0, NULL, NULL, NULL, NULL, '2026-10-10 15:02:00'),
(18, 7, 5, 'Present', 'Self check-in via face + GPS', 6, '2026-10-10 15:03:00', 0, NULL, NULL, NULL, NULL, '2026-10-10 15:03:00'),
(19, 7, 6, 'Present', 'Self check-in via face + GPS', 7, '2026-10-10 15:03:00', 0, NULL, NULL, NULL, NULL, '2026-10-10 15:03:00'),
(20, 7, 8, 'Late', 'Coach recorded manually', 2, '2026-10-10 15:20:00', 0, NULL, NULL, NULL, NULL, '2026-10-10 15:20:00'),
(21, 8, 4, 'Present', NULL, 2, '2026-10-08 15:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-08 15:05:00'),
(22, 8, 5, 'Present', NULL, 2, '2026-10-08 15:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-08 15:05:00'),
(23, 8, 6, 'Present', NULL, 2, '2026-10-08 15:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-08 15:05:00'),
(24, 8, 8, 'Present', NULL, 2, '2026-10-08 15:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-08 15:05:00'),
(25, 8, 11, 'Present', NULL, 2, '2026-10-08 15:05:00', 0, NULL, NULL, NULL, NULL, '2026-10-08 15:05:00'),
(26, 8, 12, 'Late', 'Arrived after warm-up', 2, '2026-10-08 15:30:00', 0, NULL, NULL, NULL, NULL, '2026-10-08 15:30:00');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_checkins`
--

CREATE TABLE `attendance_checkins` (
  `checkin_id` int(11) NOT NULL,
  `training_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `method` enum('FaceGPS','Manual') NOT NULL DEFAULT 'FaceGPS',
  `face_score` decimal(5,4) DEFAULT NULL,
  `distance_m` int(11) DEFAULT NULL,
  `status` enum('Present','Late') NOT NULL DEFAULT 'Present',
  `flagged` tinyint(1) NOT NULL DEFAULT 0,
  `flag_reason` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_checkins`
--

INSERT INTO `attendance_checkins` (`checkin_id`, `training_id`, `member_id`, `method`, `face_score`, `distance_m`, `status`, `flagged`, `flag_reason`, `recorded_by`, `created_at`) VALUES
(1, 7, 4, 'FaceGPS', '0.9341', 22, 'Present', 0, NULL, 4, '2026-10-10 15:02:00'),
(2, 7, 5, 'FaceGPS', '0.9012', 41, 'Present', 0, NULL, 6, '2026-10-10 15:03:00'),
(3, 7, 6, 'FaceGPS', '0.8887', 30, 'Present', 0, NULL, 7, '2026-10-10 15:03:00'),
(4, 7, 8, 'Manual', NULL, NULL, 'Present', 1, 'Low confidence - coach override', 2, '2026-10-10 15:20:00'),
(5, 8, 4, 'FaceGPS', '0.9176', 18, 'Present', 0, NULL, 4, '2026-10-08 15:05:00'),
(6, 8, 6, 'FaceGPS', '0.8744', 55, 'Present', 0, NULL, 7, '2026-10-08 15:06:00');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `event_id` int(11) NOT NULL,
  `event_name` varchar(100) NOT NULL,
  `event_type` enum('Performance','Tournament','Outreach') NOT NULL,
  `event_date` date NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `target_player_category` enum('Public','Private','Mixed') NOT NULL DEFAULT 'Mixed',
  `expected_participants` int(11) NOT NULL DEFAULT 8,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_voided` tinyint(1) DEFAULT 0,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `handler_id` int(11) DEFAULT NULL,
  `previous_handler_id` int(11) DEFAULT NULL,
  `needs_handler` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`event_id`, `event_name`, `event_type`, `event_date`, `location`, `description`, `target_player_category`, `expected_participants`, `created_at`, `updated_at`, `is_voided`, `voided_at`, `voided_by`, `void_reason`, `created_by`, `updated_by`, `handler_id`, `previous_handler_id`, `needs_handler`) VALUES
(1, 'Mandaue City Invitational', 'Tournament', '2026-10-18', 'Mandaue City Sports Complex', 'Open tournament hosted with the local LGU. All categories welcome.', 'Mixed', 40, '2026-09-30 09:00:00', '2026-09-30 09:00:00', 0, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0),
(2, 'Arnis Demonstration Night', 'Performance', '2026-10-04', 'Sitio San Jose Gym, Mandaue City', 'Public demo night for parents and supporters.', 'Mixed', 25, '2026-10-01 13:00:00', '2026-10-11 18:08:12', 0, NULL, NULL, NULL, 2, NULL, 2, NULL, 0),
(3, 'Community Self-Defense Outreach', 'Outreach', '2026-10-25', 'Maguikay Barangay Hall', 'Free self-defense seminar open to the whole barangay.', 'Public', 30, '2026-10-07 11:00:00', '2026-10-07 11:00:00', 0, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0),
(4, 'Cebu Regional Qualifiers', 'Tournament', '2026-09-20', 'Cebu City Sports Center', 'Qualifying tournament for the regional selection.', 'Mixed', 50, '2026-09-05 08:00:00', '2026-09-05 08:00:00', 0, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `event_documents`
--

CREATE TABLE `event_documents` (
  `document_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `file_type` enum('Image','Video','Receipt','Note') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `uploaded_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_voided` tinyint(1) DEFAULT 0,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_documents`
--

INSERT INTO `event_documents` (`document_id`, `event_id`, `file_type`, `file_path`, `uploaded_at`, `uploaded_by`, `updated_by`, `updated_at`, `is_voided`, `voided_at`, `voided_by`, `void_reason`) VALUES
(1, 1, 'Receipt', 'https://res.cloudinary.com/df8bufsta/image/upload/v1/event_documents/1/venue-deposit.png', '2026-10-02 09:30:00', 1, NULL, '2026-10-02 09:30:00', 0, NULL, NULL, NULL),
(2, 1, 'Image', 'https://res.cloudinary.com/df8bufsta/image/upload/v1/event_documents/1/invitational-poster.png', '2026-10-02 09:35:00', 1, NULL, '2026-10-02 09:35:00', 0, NULL, NULL, NULL),
(3, 2, 'Image', 'https://res.cloudinary.com/df8bufsta/image/upload/v1/event_documents/2/demo-night-01.png', '2026-10-04 21:00:00', 2, NULL, '2026-10-04 21:00:00', 0, NULL, NULL, NULL),
(4, 2, 'Note', 'https://res.cloudinary.com/df8bufsta/image/upload/v1/event_documents/2/lineup.txt', '2026-10-04 21:05:00', 2, NULL, '2026-10-04 21:05:00', 0, NULL, NULL, NULL),
(5, 4, 'Receipt', 'https://res.cloudinary.com/df8bufsta/image/upload/v1/event_documents/4/registration-fees.png', '2026-09-10 10:00:00', 1, NULL, '2026-09-10 10:00:00', 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `event_participants`
--

CREATE TABLE `event_participants` (
  `participant_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `role` varchar(255) DEFAULT NULL,
  `attendance_status` enum('Present','Late','Absent') DEFAULT NULL,
  `attendance_remarks` varchar(255) DEFAULT NULL,
  `attendance_recorded_by` int(11) DEFAULT NULL,
  `attendance_recorded_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_voided` tinyint(1) DEFAULT 0,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_participants`
--

INSERT INTO `event_participants` (`participant_id`, `event_id`, `member_id`, `role`, `attendance_status`, `attendance_remarks`, `attendance_recorded_by`, `attendance_recorded_at`, `created_at`, `is_voided`, `voided_at`, `voided_by`, `void_reason`, `created_by`, `updated_by`, `updated_at`) VALUES
(1, 1, 2, 'Coach / Referee', NULL, NULL, NULL, NULL, '2026-10-02 10:00:00', 0, NULL, NULL, NULL, 1, NULL, '2026-10-02 10:00:00'),
(2, 1, 4, 'Competitor', NULL, NULL, NULL, NULL, '2026-10-02 10:05:00', 0, NULL, NULL, NULL, 1, NULL, '2026-10-02 10:05:00'),
(3, 1, 8, 'Competitor', NULL, NULL, NULL, NULL, '2026-10-02 10:05:00', 0, NULL, NULL, NULL, 1, NULL, '2026-10-02 10:05:00'),
(4, 1, 5, 'Performer', NULL, NULL, NULL, NULL, '2026-10-02 10:06:00', 0, NULL, NULL, NULL, 1, NULL, '2026-10-02 10:06:00'),
(5, 2, 5, 'Lead Performer', NULL, NULL, NULL, NULL, '2026-10-01 13:30:00', 0, NULL, NULL, NULL, 2, NULL, '2026-10-01 13:30:00'),
(6, 2, 6, 'Performer', NULL, NULL, NULL, NULL, '2026-10-01 13:30:00', 0, NULL, NULL, NULL, 2, NULL, '2026-10-01 13:30:00'),
(7, 2, 4, 'Photographer', NULL, NULL, NULL, NULL, '2026-10-01 13:31:00', 0, NULL, NULL, NULL, 2, NULL, '2026-10-01 13:31:00'),
(8, 3, 7, 'Volunteer', NULL, NULL, NULL, NULL, '2026-10-08 09:00:00', 0, NULL, NULL, NULL, 1, NULL, '2026-10-08 09:00:00'),
(9, 3, 11, 'Volunteer', NULL, NULL, NULL, NULL, '2026-10-08 09:05:00', 0, NULL, NULL, NULL, 1, NULL, '2026-10-08 09:05:00'),
(10, 4, 6, 'Competitor', NULL, NULL, NULL, NULL, '2026-09-10 08:00:00', 0, NULL, NULL, NULL, 1, NULL, '2026-09-10 08:00:00'),
(11, 4, 12, 'Competitor', NULL, NULL, NULL, NULL, '2026-09-10 08:00:00', 0, NULL, NULL, NULL, 1, NULL, '2026-09-10 08:00:00'),
(12, 4, 8, 'Team Coach', NULL, NULL, NULL, NULL, '2026-09-10 08:01:00', 0, NULL, NULL, NULL, 1, NULL, '2026-09-10 08:01:00');

-- --------------------------------------------------------

--
-- Table structure for table `funds`
--

CREATE TABLE `funds` (
  `fund_id` int(11) NOT NULL,
  `transaction_type` enum('Income','Expense') NOT NULL,
  `source_or_purpose` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `transaction_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `related_event_id` int(11) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_voided` tinyint(1) DEFAULT 0,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `funds`
--

INSERT INTO `funds` (`fund_id`, `transaction_type`, `source_or_purpose`, `amount`, `transaction_date`, `notes`, `related_event_id`, `recorded_by`, `created_at`, `is_voided`, `voided_at`, `voided_by`, `void_reason`, `updated_by`, `updated_at`) VALUES
(1, 'Income', 'Monthly club dues - October', '3500.00', '2026-10-01', 'Collected from active members', NULL, 3, '2026-10-01 17:00:00', 0, NULL, NULL, NULL, NULL, NULL),
(2, 'Expense', 'Equipment: rattan sticks and pads', '1200.00', '2026-10-02', NULL, 1, 3, '2026-10-02 10:00:00', 0, NULL, NULL, NULL, NULL, NULL),
(3, 'Income', 'Venue deposit refund', '500.00', '2026-10-03', 'Refunded overpayment', NULL, 1, '2026-10-03 09:30:00', 0, NULL, NULL, NULL, NULL, NULL),
(4, 'Expense', 'Tournament permits - Mandaue Gym', '800.00', '2026-10-05', NULL, 1, 3, '2026-10-05 11:00:00', 0, NULL, NULL, NULL, NULL, NULL),
(5, 'Income', 'Registration fees - Regional Qualifiers', '1500.00', '2026-09-25', NULL, 4, 1, '2026-09-25 15:00:00', 0, NULL, NULL, NULL, NULL, NULL),
(6, 'Expense', 'Transport - Regional Qualifiers', '950.00', '2026-09-27', NULL, 4, 3, '2026-09-27 08:00:00', 0, NULL, NULL, NULL, NULL, NULL),
(7, 'Expense', 'Youth gloves donation drive', '600.00', '2026-10-08', NULL, NULL, 3, '2026-10-08 16:00:00', 0, NULL, NULL, NULL, NULL, NULL),
(8, 'Income', 'Outreach sponsor donation', '2500.00', '2026-10-09', NULL, 3, 1, '2026-10-09 10:00:00', 0, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `member_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `dob` date DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `affiliated_school` varchar(150) DEFAULT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_contact` varchar(20) DEFAULT NULL,
  `payment_notes` varchar(255) DEFAULT NULL,
  `rank` enum('None','I. White Belt','II. Yellow Belt','III. Green Belt','IV. Blue Belt','V. Brown Belt','VI. Black Belt','1st Degree- Junior Instructor','2nd Degree- Junior Instructor','3rd Degree- Instructor','4th Degree- Instructor','5th Degree- Senior Instructor','6th Degree- Senior Instructor','7th Degree- Junior Master','8th Degree- Master','9th Degree- Senior Master','VII. Red Belt','10th Degree- Grandmaster','11th Degree- Senior Grandmaster','12th Degree- Superior Grandmaster') NOT NULL DEFAULT 'None',
  `official_rank` enum('None','I. White Belt','II. Yellow Belt','III. Green Belt','IV. Blue Belt','V. Brown Belt','VI. Black Belt','VII. Red Belt','1st Degree- Junior Instructor','2nd Degree- Junior Instructor','3rd Degree- Instructor','4th Degree- Instructor','5th Degree- Senior Instructor','6th Degree- Senior Instructor','7th Degree- Junior Master','8th Degree- Master','9th Degree- Senior Master','10th Degree- Grandmaster','11th Degree- Senior Grandmaster','12th Degree- Superior Grandmaster') NOT NULL DEFAULT 'None',
  `rank_verification_status` enum('Pending Verification','More Evidence Required','Assessment Required','Verified','Not Verified') NOT NULL DEFAULT 'Pending Verification',
  `rank_verified_by` int(11) DEFAULT NULL,
  `rank_verified_at` datetime DEFAULT NULL,
  `rank_verification_notes` text DEFAULT NULL,
  `member_type` enum('Head Coach','Coach','Player') NOT NULL DEFAULT 'Player',
  `player_category` enum('Public','Private','Mixed') NOT NULL DEFAULT 'Public',
  `training_preference` enum('Group','Private','Flexible') NOT NULL DEFAULT 'Group',
  `status` enum('Active','Inactive','On Leave') DEFAULT 'Active',
  `profile_img` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_voided` tinyint(1) DEFAULT 0,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `face_descriptor` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`member_id`, `full_name`, `dob`, `email`, `height`, `weight`, `contact_number`, `address`, `affiliated_school`, `guardian_name`, `guardian_contact`, `payment_notes`, `rank`, `official_rank`, `rank_verification_status`, `rank_verified_by`, `rank_verified_at`, `rank_verification_notes`, `member_type`, `player_category`, `training_preference`, `status`, `profile_img`, `created_by`, `updated_by`, `created_at`, `updated_at`, `is_voided`, `voided_at`, `voided_by`, `void_reason`, `face_descriptor`) VALUES
(1, 'Master Ramon Dela Cruz', '1978-06-15', 'ramon.delacruz@arnis.test', '172.00', '78.00', '09171234501', 'Cabancalan, Mandaue City', NULL, NULL, NULL, 'Club founder - handles public and private training.', '8th Degree- Master', 'None', 'Pending Verification', NULL, NULL, NULL, 'Head Coach', 'Mixed', 'Group', 'Active', NULL, 1, 1, '2026-09-18 09:00:00', '2026-09-18 09:00:00', 0, NULL, NULL, NULL, NULL),
(2, 'Coach Paulo Reyes', '1987-03-22', 'paulo.reyes@arnis.test', '170.00', '70.00', '09171234502', 'Tipolo, Mandaue City', NULL, NULL, NULL, 'Handles public and private training sessions.', '3rd Degree- Instructor', 'None', 'Pending Verification', NULL, NULL, NULL, 'Coach', 'Mixed', 'Group', 'Active', NULL, 1, 1, '2026-09-18 09:05:00', '2026-09-18 09:05:00', 0, NULL, NULL, NULL, NULL),
(3, 'Coach Ana Mercado', '1992-09-08', 'ana.mercado@arnis.test', '163.00', '58.00', '09171234503', 'Basak, Mandaue City', NULL, NULL, NULL, 'Private sessions and one-on-one coaching.', '1st Degree- Junior Instructor', 'None', 'Pending Verification', NULL, NULL, NULL, 'Coach', 'Private', 'Private', 'Active', NULL, 1, 1, '2026-09-18 09:10:00', '2026-09-18 09:10:00', 0, NULL, NULL, NULL, NULL),
(4, 'Miguel Santillan', '2009-11-02', 'migs.santillan@example.com', '168.00', '61.00', '09171234504', 'Paknaan, Mandaue City', 'Paknaan National High School', 'Rosa Santillan', '09171234505', 'Mixed player applicant - may join public and private sessions.', 'II. Yellow Belt', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Mixed', 'Flexible', 'Active', NULL, 1, 1, '2026-09-19 14:00:00', '2026-09-19 14:00:00', 0, NULL, NULL, NULL, NULL),
(5, 'Anna Marie Lim', '2011-02-14', 'anna.lim@example.com', '152.00', '43.00', '09171234506', 'Looc, Mandaue City', 'Looc Elementary School', 'Melchor Lim', '09171234507', 'Public player applicant - free / school-based training.', 'I. White Belt', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Public', 'Group', 'Active', NULL, 1, 1, '2026-09-19 14:10:00', '2026-09-19 14:10:00', 0, NULL, NULL, NULL, NULL),
(6, 'Liam Cruz', '2008-07-19', 'liam.cruz@example.com', '165.00', '55.00', '09171234508', 'Guizo, Mandaue City', 'Mandaue City Comprehensive School', 'Nora Cruz', '09171234509', 'Public player applicant - free / school-based training.', 'III. Green Belt', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Public', 'Group', 'Active', NULL, 1, 1, '2026-09-20 08:30:00', '2026-09-20 08:30:00', 0, NULL, NULL, NULL, NULL),
(7, 'Giana Reyes', '2010-03-14', 'giana.reyes@example.com', '158.00', '47.00', '09171234510', 'San Jose, Mandaue City', 'Maguikay Elementary School', 'Marissa Reyes', '09171234511', 'Public player applicant - free / school-based training.', 'None', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Public', 'Group', 'Active', NULL, 1, 1, '2026-09-22 16:20:00', '2026-09-22 16:20:00', 0, NULL, NULL, NULL, NULL),
(8, 'Joshua Ramos', '2004-12-01', 'joshua.ramos@arnis.test', '173.00', '68.00', '09171234512', 'Canduman, Mandaue City', 'Cebu Institute of Technology', NULL, NULL, 'Mixed player applicant - may join public and private sessions.', 'IV. Blue Belt', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Mixed', 'Flexible', 'Active', NULL, 1, 1, '2026-09-24 10:00:00', '2026-09-24 10:00:00', 0, NULL, NULL, NULL, NULL),
(9, 'Bea Navarro', '2005-08-30', 'bea.navarro@arnis.test', '161.00', '52.00', '09171234513', 'Banilad, Cebu City', 'Cebu Institute of Technology', 'Gemma Navarro', '09171234520', 'Private student - pays training fee.', 'V. Brown Belt', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Private', 'Private', 'On Leave', NULL, 1, 1, '2026-09-25 11:00:00', '2026-09-25 11:00:00', 0, NULL, NULL, NULL, NULL),
(10, 'Karl Mendoza', '2012-04-10', 'karl.mendoza@arnis.test', '145.00', '38.00', '09171234514', 'Labogon, Mandaue City', 'Labogon Elementary School', 'Felipe Mendoza', '09171234515', 'Public player applicant - free / school-based training.', 'I. White Belt', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Public', 'Group', 'Inactive', NULL, 1, 1, '2026-09-28 09:40:00', '2026-09-28 09:40:00', 0, NULL, NULL, NULL, NULL),
(11, 'Trisha Villanueva', '2011-06-25', 'trisha.villanueva@arnis.test', '150.00', '41.00', '09171234516', 'Umapad, Mandaue City', 'Umapad Elementary School', 'Cynthia Villanueva', '09171234517', 'Public player applicant - free / school-based training.', 'None', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Public', 'Group', 'Active', NULL, 1, 1, '2026-10-01 07:30:00', '2026-10-01 07:30:00', 0, NULL, NULL, NULL, NULL),
(12, 'Diego Fernandez', '1998-05-11', 'diego.fernandez@arnis.test', '180.00', '82.00', '09171234518', 'North Reclamation, Cebu City', 'University of San Carlos', NULL, NULL, 'Mixed player applicant - may join public and private sessions.', 'VI. Black Belt', 'None', 'Pending Verification', NULL, NULL, NULL, 'Player', 'Mixed', 'Flexible', 'Active', NULL, 1, 15, '2026-10-02 15:15:00', '2026-10-17 14:58:16', 0, NULL, NULL, NULL, '[-0.08572076261043548583984375,0.07390008866786956787109375,0.074464507400989532470703125,-0.0256911776959896087646484375,-0.02461901120841503143310546875,-0.101185940206050872802734375,-0.074551545083522796630859375,-0.10860021412372589111328125,0.129699766635894775390625,-0.078828014433383941650390625,0.2697302401065826416015625,-0.03982533514499664306640625,-0.17048059403896331787109375,-0.098095335066318511962890625,-0.004632334224879741668701171875,0.1672925055027008056640625,-0.2104995250701904296875,-0.072374574840068817138671875,-0.059231169521808624267578125,0.0055011198855936527252197265625,0.072178252041339874267578125,-0.01186644099652767181396484375,0.02911433391273021697998046875,0.014608667232096195220947265625,-0.0737164318561553955078125,-0.324733316898345947265625,-0.1281853616237640380859375,-0.0496040619909763336181640625,0.039669930934906005859375,-0.0360969193279743194580078125,-0.0586502738296985626220703125,-0.02340782992541790008544921875,-0.2773043215274810791015625,-0.0822976529598236083984375,0.014898289926350116729736328125,0.05754385888576507568359375,-0.02464990317821502685546875,-0.066298924386501312255859375,0.1575425565242767333984375,-0.0095173530280590057373046875,-0.18311642110347747802734375,0.01367810927331447601318359375,0.02480297721922397613525390625,0.1890480816364288330078125,0.1758340895175933837890625,0.0823941528797149658203125,0.00531087629497051239013671875,-0.14253346621990203857421875,0.0811299979686737060546875,-0.1173290312290191650390625,0.067941129207611083984375,0.151218116283416748046875,0.080844275653362274169921875,0.09270898997783660888671875,-0.004382939077913761138916015625,-0.104420281946659088134765625,0.0446981601417064666748046875,0.109140150249004364013671875,-0.11182324588298797607421875,0.003067950718104839324951171875,0.095065735280513763427734375,-0.07889319956302642822265625,-0.0152536816895008087158203125,-0.0698603689670562744140625,0.28229939937591552734375,0.0846853554248809814453125,-0.1242136061191558837890625,-0.19569052755832672119140625,0.098479352891445159912109375,-0.1021533906459808349609375,-0.085122056305408477783203125,0.030725501477718353271484375,-0.1303555071353912353515625,-0.140211999416351318359375,-0.3550239503383636474609375,0.036002404987812042236328125,0.381875693798065185546875,0.040446929633617401123046875,-0.23689158260822296142578125,0.06218831241130828857421875,-0.11142282187938690185546875,-0.023967616260051727294921875,0.1589584648609161376953125,0.17677216231822967529296875,0.0253097154200077056884765625,0.02986103110015392303466796875,-0.088909626007080078125,-0.0249753333628177642822265625,0.21628582477569580078125,-0.071867994964122772216796875,-0.0307921729981899261474609375,0.18137137591838836669921875,-0.05406059324741363525390625,0.06904654204845428466796875,0.001188409631140530109405517578125,-0.0053713009692728519439697265625,-0.06995566189289093017578125,0.0399686805903911590576171875,-0.073575317859649658203125,-0.0090847313404083251953125,0.047768138349056243896484375,0.007749850861728191375732421875,-0.0365366898477077484130859375,0.1263808906078338623046875,-0.113236717879772186279296875,0.093490861356258392333984375,0.036007039248943328857421875,0.0427531413733959197998046875,0.07011877000331878662109375,0.00829342193901538848876953125,-0.099524684250354766845703125,-0.14428858458995819091796875,0.0612795650959014892578125,-0.263632237911224365234375,0.2779872417449951171875,0.1356862485408782958984375,-0.000792977749370038509368896484375,0.110007233917713165283203125,0.0850644409656524658203125,0.180157184600830078125,-0.031439863145351409912109375,0.04171453416347503662109375,-0.1865811049938201904296875,-0.011669893749058246612548828125,0.078765571117401123046875,0.02437148801982402801513671875,0.10008768737316131591796875,-0.00182088674046099185943603515625]');

-- --------------------------------------------------------

--
-- Table structure for table `rank_history`
--

CREATE TABLE `rank_history` (
  `history_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `old_rank` varchar(100) DEFAULT NULL,
  `new_rank` varchar(100) NOT NULL,
  `promoted_by` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `promoted_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rank_history`
--

INSERT INTO `rank_history` (`history_id`, `member_id`, `old_rank`, `new_rank`, `promoted_by`, `notes`, `promoted_at`) VALUES
(1, 4, 'None', 'I. White Belt', 2, 'Started training - white belt awarded', '2026-05-10 09:00:00'),
(2, 4, 'I. White Belt', 'II. Yellow Belt', 2, 'Passed first grading', '2026-09-28 18:30:00'),
(3, 6, 'None', 'I. White Belt', 2, 'Started training - white belt awarded', '2026-06-15 16:00:00'),
(4, 6, 'I. White Belt', 'III. Green Belt', 2, 'Fast-track grading after tournament win', '2026-09-28 18:40:00'),
(5, 8, 'None', 'IV. Blue Belt', 1, 'Experienced player transferred into the club', '2026-07-20 15:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `rank_verification_history`
--

CREATE TABLE `rank_verification_history` (
  `history_id` int(11) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `reviewer_id` int(11) DEFAULT NULL,
  `method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registration_requests`
--

CREATE TABLE `registration_requests` (
  `request_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `dob` date DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `player_category` enum('Public','Private','Mixed') NOT NULL DEFAULT 'Public',
  `training_preference` enum('Group','Private','Flexible') NOT NULL DEFAULT 'Group',
  `affiliated_school` varchar(150) DEFAULT NULL,
  `prev_organization` varchar(150) DEFAULT NULL,
  `prev_instructor` varchar(100) DEFAULT NULL,
  `rank_awarded_year` varchar(4) DEFAULT NULL,
  `rank_evidence_path` varchar(255) DEFAULT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_contact` varchar(20) DEFAULT NULL,
  `payment_notes` varchar(255) DEFAULT NULL,
  `requested_rank` enum('None','I. White Belt','II. Yellow Belt','III. Green Belt','IV. Blue Belt','V. Brown Belt','VI. Black Belt','1st Degree- Junior Instructor','2nd Degree- Junior Instructor','3rd Degree- Instructor','4th Degree- Instructor','5th Degree- Senior Instructor','6th Degree- Senior Instructor','7th Degree- Junior Master','8th Degree- Master','9th Degree- Senior Master','VII. Red Belt','10th Degree- Grandmaster','11th Degree- Senior Grandmaster','12th Degree- Superior Grandmaster') NOT NULL DEFAULT 'None',
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `submitted_at` datetime DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `review_notes` varchar(255) DEFAULT NULL,
  `rank_verification_status` enum('Pending Verification','More Evidence Required','Assessment Required','Verified','Not Verified') NOT NULL DEFAULT 'Pending Verification',
  `rank_verified_by` int(11) DEFAULT NULL,
  `rank_verified_at` datetime DEFAULT NULL,
  `rank_verification_method` varchar(50) DEFAULT NULL,
  `rank_verification_notes` text DEFAULT NULL,
  `contact_ok` tinyint(1) NOT NULL DEFAULT 0,
  `privacy_consent_at` datetime DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `registration_requests`
--

INSERT INTO `registration_requests` (`request_id`, `full_name`, `dob`, `email`, `username`, `contact_number`, `address`, `player_category`, `training_preference`, `affiliated_school`, `prev_organization`, `prev_instructor`, `rank_awarded_year`, `rank_evidence_path`, `guardian_name`, `guardian_contact`, `payment_notes`, `requested_rank`, `status`, `submitted_at`, `reviewed_at`, `reviewed_by`, `review_notes`, `rank_verification_status`, `rank_verified_by`, `rank_verified_at`, `rank_verification_method`, `rank_verification_notes`, `contact_ok`, `member_id`, `user_id`) VALUES
(1, 'Juan Delgado', '2011-05-20', 'juan.delgado@example.com', 'applicant.juan', '09951112233', '723 Mabolo St, Cebu City', 'Public', 'Group', 'P. Dela Salle Elementary', NULL, NULL, NULL, NULL, 'Elena Delgado', '09951112234', 'Public player applicant - free / school-based training.', 'I. White Belt', 'Rejected', '2026-10-08 09:15:00', '2026-10-10 10:58:09', 1, NULL, 'Pending Verification', NULL, NULL, NULL, NULL, 0, NULL, NULL),
(2, 'Giana Reyes', '2010-03-14', 'giana.reyes@example.com', 'member.giana', '09953334455', '114 San Jose St, Mandaue City', 'Public', 'Group', 'Maguikay Elementary', NULL, NULL, NULL, NULL, 'Marissa Reyes', '09953334456', 'Public player applicant - free / school-based training.', 'None', 'Approved', '2026-09-20 10:00:00', '2026-09-22 14:30:00', 1, 'Guardian verified by phone', 'Pending Verification', NULL, NULL, NULL, NULL, 0, 7, 8),
(3, 'Noah Sabellano', '2012-08-08', 'noah.sabellano@example.com', NULL, '09954445566', '88 Bakilid St, Mandaue City', 'Public', 'Group', 'Basak Elementary', NULL, NULL, NULL, NULL, NULL, NULL, 'Public player applicant - free / school-based training.', 'None', 'Rejected', '2026-10-02 08:45:00', '2026-10-04 09:10:00', 1, 'Guardian could not be reached', 'Pending Verification', NULL, NULL, NULL, NULL, 0, NULL, NULL),
(4, 'Sophia Fernandez', '2009-11-02', 'sophia.fernandez@example.com', NULL, '09956667788', 'Maribago, Lapu Lapu City', 'Private', 'Private', 'Cebu Doctors University', NULL, NULL, NULL, 'uploads/rank_evidence/rank_16_20261017173151_0d34e7f8.png', NULL, NULL, 'Private student - pays training fee.', 'III. Green Belt', 'Pending', '2026-10-09 14:05:00', NULL, NULL, NULL, 'Pending Verification', NULL, NULL, NULL, NULL, 0, NULL, 16),
(5, 'Christ Jhon Denver Partosa', '2004-12-04', 'christpartosa9@gmail.com', 'testuser123', '09999999', NULL, 'Public', 'Group', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Public player applicant - free / school-based training.', 'None', 'Rejected', '2026-10-10 19:59:04', '2026-10-10 20:26:45', 1, NULL, 'Pending Verification', NULL, NULL, NULL, NULL, 0, NULL, NULL),
(6, 'Lebron James', '2004-12-04', 'Lebron9@gmail.com', 'lebron9', '0999999', 'Sitio San Jose Maguikay Mandaue City, Cebu', 'Mixed', 'Flexible', 'Maguikay National Highschool', NULL, NULL, NULL, NULL, 'Michael Jordan', '09999999', 'Mixed player: joins both public and private session', 'VI. Black Belt', 'Rejected', '2026-10-10 20:24:58', '2026-10-10 20:26:50', 1, NULL, 'Pending Verification', NULL, NULL, NULL, NULL, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `trainings`
--

CREATE TABLE `trainings` (
  `training_id` int(11) NOT NULL,
  `training_date` date NOT NULL,
  `training_type` enum('Regular','Private','Custom') DEFAULT 'Regular',
  `target_group` enum('Public','Private','Mixed') NOT NULL DEFAULT 'Mixed',
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `venue_type` enum('School','Private Venue','Custom') NOT NULL DEFAULT 'Custom',
  `description` varchar(255) DEFAULT NULL,
  `fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `coach_id` int(11) DEFAULT NULL,
  `previous_coach_id` int(11) DEFAULT NULL,
  `needs_coach` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `is_voided` tinyint(1) DEFAULT 0,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `venue_lat` decimal(10,7) DEFAULT NULL,
  `venue_lng` decimal(10,7) DEFAULT NULL,
  `geofence_m` int(11) NOT NULL DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trainings`
--

INSERT INTO `trainings` (`training_id`, `training_date`, `training_type`, `target_group`, `start_time`, `end_time`, `location`, `venue_type`, `description`, `fee_amount`, `coach_id`, `previous_coach_id`, `needs_coach`, `created_by`, `created_at`, `updated_at`, `updated_by`, `is_voided`, `voided_at`, `voided_by`, `void_reason`, `venue_lat`, `venue_lng`, `geofence_m`) VALUES
(1, '2026-10-12', 'Regular', 'Mixed', '15:00:00', '17:00:00', 'Sitio San Jose Gym, Mandaue City', 'School', 'Weekly group training', '0.00', 2, NULL, 0, 2, '2026-10-01 08:00:00', '2026-10-01 08:00:00', NULL, 0, NULL, NULL, NULL, '10.3444183', '123.9372977', 100),
(2, '2026-10-17', 'Regular', 'Public', '15:00:00', '17:00:00', 'Sitio San Jose Gym, Mandaue City', 'School', 'Saturday public class', '0.00', 2, NULL, 0, 2, '2026-10-02 08:30:00', '2026-10-18 05:19:40', NULL, 0, NULL, NULL, NULL, '10.3441777', '123.9379837', 100),
(3, '2026-09-28', 'Regular', 'Mixed', '15:00:00', '17:00:00', 'Sitio San Jose Gym, Mandaue City', 'School', 'Monday group training', '0.00', 2, NULL, 0, 2, '2026-09-20 10:00:00', '2026-09-20 10:00:00', NULL, 0, NULL, NULL, NULL, '10.3444183', '123.9372977', 100),
(4, '2026-10-03', 'Regular', 'Public', '08:00:00', '10:00:00', 'Barangay Court, Maguikay, Mandaue City', 'Custom', 'Saturday morning public class', '0.00', 2, NULL, 0, 2, '2026-10-01 09:00:00', '2026-10-01 09:00:00', NULL, 0, NULL, NULL, NULL, NULL, NULL, 100),
(5, '2026-10-24', 'Private', 'Private', '09:00:00', '11:00:00', 'Private Venue - Banilad, Cebu City', 'Private Venue', 'Private session with Head Coach', '350.00', 1, NULL, 0, 1, '2026-10-06 16:00:00', '2026-10-18 05:18:47', NULL, 0, NULL, NULL, NULL, '10.3413827', '123.9196727', 100),
(6, '2026-10-06', 'Regular', 'Mixed', '15:00:00', '17:00:00', 'Sitio San Jose Gym, Mandaue City', 'School', 'Head Coach Tuesday session', '0.00', 1, NULL, 0, 1, '2026-10-04 07:45:00', '2026-10-04 07:45:00', NULL, 0, NULL, NULL, NULL, '10.3444183', '123.9372977', 100),
(7, '2026-10-10', 'Regular', 'Mixed', '15:00:00', '17:00:00', 'Sitio San Jose Gym, Mandaue City', 'School', 'Same-day session - use this for face + GPS self check-in', '0.00', 2, NULL, 0, 2, '2026-10-08 12:00:00', '2026-10-08 12:00:00', NULL, 0, NULL, NULL, NULL, '10.3444183', '123.9372977', 100),
(8, '2026-10-08', 'Regular', 'Mixed', '15:00:00', '17:00:00', 'Sitio San Jose Gym, Mandaue City', 'School', 'Thursday evening training', '0.00', 2, NULL, 0, 2, '2026-10-05 14:00:00', '2026-10-05 14:00:00', NULL, 0, NULL, NULL, NULL, '10.3444183', '123.9372977', 100);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `mobile_verified_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `session_version` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('SuperAdmin','Head Coach','Coach/Trainer','Officer','Member','Applicant') NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `privacy_consent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `actor_name` varchar(100) NOT NULL DEFAULT 'System',
  `actor_role` varchar(50) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `target_table` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_user_id` (`user_id`),
  KEY `idx_audit_logs_created_at` (`created_at`),
  KEY `idx_audit_logs_module_action` (`module`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `email_verification_tokens`
--

CREATE TABLE `email_verification_tokens` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(100) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email_verification_tokens_token` (`token`),
  KEY `idx_email_verification_tokens_user_id` (`user_id`),
  KEY `idx_email_verification_tokens_expires_at` (`expires_at`),
  CONSTRAINT `fk_email_verification_tokens_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `member_id`, `email_verified_at`, `mobile_verified_at`, `last_login_at`, `session_version`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Super Administrator', 'superadmin', 'superadmin@arnisconnect.local', NULL, NULL, NULL, '2026-10-31 16:04:09', 2, '$2y$12$brQom6OTSTj1s7FqWs0pYOlboQBVMknUlP9gTcyZDvtlM1tUxVi1O', 'SuperAdmin', 1, '2026-01-21 22:17:32', '2026-10-31 16:04:09'),
(2, 'Coach Paulo Reyes', 'coach.paulo', 'coach.paulo@arnis.test', 2, NULL, NULL, NULL, 1, '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6', 'Coach/Trainer', 1, '2026-10-01 08:00:00', '2026-10-01 08:00:00'),
(3, 'Liza Marquez', 'officer.liza', 'officer.liza@arnis.test', NULL, NULL, NULL, '2026-10-11 18:34:44', 2, '$2y$12$PHqqVDQodBA9lq6O4jagNeZOtwVx8OolZau4BkPWvt6zbU1W5x63m', 'Officer', 1, '2026-10-01 08:05:00', '2026-10-11 18:34:44'),
(4, 'Miguel Santillan', 'member.migs', 'migs.santillan@example.com', 4, NULL, NULL, '2026-10-10 19:56:16', 2, '$2y$12$UIIIZHG6dmpfaZLEaIJGFukTGJw49iz3NhHNjafOOlN6YLc1btjXa', 'Member', 1, '2026-10-01 08:10:00', '2026-10-10 19:56:16'),
(6, 'Anna Marie Lim', 'member.anna', 'anna.lim@example.com', 5, NULL, NULL, NULL, 1, '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6', 'Member', 1, '2026-10-02 10:00:00', '2026-10-02 10:00:00'),
(7, 'Liam Cruz', 'member.liam', 'liam.cruz@example.com', 6, NULL, NULL, NULL, 1, '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6', 'Member', 1, '2026-10-02 10:05:00', '2026-10-02 10:05:00'),
(8, 'Giana Reyes', 'member.giana', 'giana.reyes@example.com', 7, NULL, NULL, '2026-10-17 20:24:48', 2, '$2y$12$zm3g6Vg/Nih8hDooq/Xffux1Gcyza309fIKQ2RE4clfOUkPxRGev2', 'Member', 1, '2026-09-22 16:25:00', '2026-10-17 20:24:48'),
(9, 'Master Ramon Dela Cruz', 'member.1', 'ramon.delacruz@arnis.test', 1, NULL, NULL, '2026-10-11 18:35:50', 2, '$2y$12$fV5tJYOtNTURF5K8SV8vZu/Do3qCT274VDFWTt6lBUvG4REy2fIXC', 'Head Coach', 1, '2026-10-12 00:39:43', '2026-10-11 18:35:50'),
(10, 'Coach Ana Mercado', 'member.3', 'ana.mercado@arnis.test', 3, NULL, NULL, '2026-10-11 18:30:11', 2, '$2y$12$ugk06BTkEilT5EQO0qMLDOiE6VroUqkFz/oRG27bQEAUJntjj.ksm', 'Coach/Trainer', 1, '2026-10-12 00:39:43', '2026-10-11 18:30:11'),
(11, 'Joshua Ramos', 'member.8', 'joshua.ramos@arnis.test', 8, NULL, NULL, NULL, 1, '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6', 'Member', 1, '2026-10-12 00:39:43', '2026-10-12 00:39:43'),
(12, 'Bea Navarro', 'member.9', 'bea.navarro@arnis.test', 9, NULL, NULL, NULL, 1, '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6', 'Member', 1, '2026-10-12 00:39:43', '2026-10-12 00:39:43'),
(13, 'Karl Mendoza', 'member.10', 'karl.mendoza@arnis.test', 10, NULL, NULL, NULL, 1, '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6', 'Member', 0, '2026-10-12 00:39:43', '2026-10-12 00:39:43'),
(14, 'Trisha Villanueva', 'member.11', 'trisha.villanueva@arnis.test', 11, NULL, NULL, NULL, 1, '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6', 'Member', 1, '2026-10-12 00:39:43', '2026-10-12 00:39:43'),
(15, 'Diego Fernandez', 'member.12', 'diego.fernandez@arnis.test', 12, NULL, NULL, '2026-10-28 22:53:30', 2, '$2y$12$0yqEgA4hodenS/OvRPgn/ONlel5So2wSU0O//6UO14YLNRs/h6cL6', 'Member', 1, '2026-10-12 00:39:43', '2026-10-28 22:53:30'),
(16, 'Sophia Fernandez', 'applicant.4', 'sophia.fernandez@example.com', NULL, NULL, NULL, '2026-10-17 14:29:17', 2, '$2y$12$QzoftIVfogi1ks7UyjL5bOIcJMnwYV7huyaomvrEn27o1TzpIV.OO', 'Applicant', 1, '2026-10-09 14:05:00', '2026-10-17 14:29:17');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `achievements`
--
ALTER TABLE `achievements`
  ADD PRIMARY KEY (`achievement_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `fk_achievements_voided_by` (`voided_by`),
  ADD KEY `fk_achievements_created_by` (`created_by`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `uniq_attendance_training_member` (`training_id`,`member_id`),
  ADD KEY `training_id` (`training_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `fk_attendance_voided_by` (`voided_by`),
  ADD KEY `fk_attendance_updated_by` (`updated_by`);

--
-- Indexes for table `attendance_checkins`
--
ALTER TABLE `attendance_checkins`
  ADD PRIMARY KEY (`checkin_id`),
  ADD UNIQUE KEY `uniq_checkin_training_member` (`training_id`,`member_id`),
  ADD KEY `fk_checkin_training` (`training_id`),
  ADD KEY `fk_checkin_member` (`member_id`),
  ADD KEY `fk_checkin_recorded_by` (`recorded_by`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `fk_events_voided_by` (`voided_by`),
  ADD KEY `fk_events_created_by` (`created_by`),
  ADD KEY `fk_events_updated_by` (`updated_by`),
  ADD KEY `fk_events_handler_id` (`handler_id`),
  ADD KEY `fk_events_previous_handler_id` (`previous_handler_id`),
  ADD KEY `idx_events_target_player_category` (`target_player_category`);

--
-- Indexes for table `event_documents`
--
ALTER TABLE `event_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `fk_event_documents_uploaded_by` (`uploaded_by`),
  ADD KEY `fk_event_documents_updated_by` (`updated_by`),
  ADD KEY `fk_event_documents_voided_by` (`voided_by`);

--
-- Indexes for table `event_participants`
--
ALTER TABLE `event_participants`
  ADD PRIMARY KEY (`participant_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `fk_event_participants_voided_by` (`voided_by`),
  ADD KEY `fk_event_participants_created_by` (`created_by`),
  ADD KEY `fk_event_participants_updated_by` (`updated_by`);

--
-- Indexes for table `funds`
--
ALTER TABLE `funds`
  ADD PRIMARY KEY (`fund_id`),
  ADD KEY `related_event_id` (`related_event_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `fk_funds_voided_by` (`voided_by`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`member_id`),
  ADD KEY `fk_members_voided_by` (`voided_by`),
  ADD KEY `fk_members_created_by` (`created_by`),
  ADD KEY `fk_members_updated_by` (`updated_by`),
  ADD KEY `idx_members_rank_verified_by` (`rank_verified_by`),
  ADD KEY `idx_members_status` (`status`),
  ADD KEY `idx_members_rank` (`rank`),
  ADD KEY `idx_members_member_type` (`member_type`),
  ADD KEY `idx_members_is_voided` (`is_voided`),
  ADD KEY `idx_members_created_at` (`created_at`),
  ADD KEY `idx_members_full_name` (`full_name`),
  ADD KEY `idx_members_contact` (`contact_number`),
  ADD KEY `idx_members_player_category` (`player_category`),
  ADD KEY `idx_members_affiliated_school` (`affiliated_school`),
  ADD KEY `idx_members_email` (`email`),
  ADD KEY `idx_members_created_by` (`created_by`),
  ADD KEY `idx_members_updated_by` (`updated_by`);

--
-- Indexes for table `rank_history`
--
ALTER TABLE `rank_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `fk_rank_history_member` (`member_id`),
  ADD KEY `fk_rank_history_promoted_by` (`promoted_by`);

--
-- Indexes for table `rank_verification_history`
--
ALTER TABLE `rank_verification_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `idx_request` (`request_id`),
  ADD KEY `idx_member` (`member_id`),
  ADD KEY `idx_reviewer` (`reviewer_id`);

--
-- Indexes for table `registration_requests`
--
ALTER TABLE `registration_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_registration_status` (`status`),
  ADD KEY `idx_registration_player_category` (`player_category`),
  ADD KEY `idx_registration_email` (`email`),
  ADD KEY `fk_registration_reviewed_by` (`reviewed_by`),
  ADD KEY `idx_registration_member_id` (`member_id`),
  ADD KEY `fk_registration_user_id` (`user_id`),
  ADD KEY `fk_registration_rank_verified_by` (`rank_verified_by`);

--
-- Indexes for table `trainings`
--
ALTER TABLE `trainings`
  ADD PRIMARY KEY (`training_id`),
  ADD KEY `coach_id` (`coach_id`),
  ADD KEY `fk_trainings_voided_by` (`voided_by`),
  ADD KEY `fk_trainings_created_by` (`created_by`),
  ADD KEY `fk_trainings_updated_by` (`updated_by`),
  ADD KEY `idx_trainings_is_voided` (`is_voided`),
  ADD KEY `idx_trainings_training_date` (`training_date`),
  ADD KEY `idx_trainings_training_type` (`training_type`),
  ADD KEY `idx_trainings_coach_id` (`coach_id`),
  ADD KEY `fk_trainings_previous_coach_id` (`previous_coach_id`),
  ADD KEY `idx_trainings_location` (`location`),
  ADD KEY `idx_trainings_main_query` (`is_voided`,`training_date`,`training_type`),
  ADD KEY `idx_trainings_target_group` (`target_group`),
  ADD KEY `idx_trainings_venue_type` (`venue_type`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `uniq_users_email` (`email`),
  ADD KEY `idx_users_member_id` (`member_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `achievements`
--
ALTER TABLE `achievements`
  MODIFY `achievement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `attendance_checkins`
--
ALTER TABLE `attendance_checkins`
  MODIFY `checkin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `event_documents`
--
ALTER TABLE `event_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `event_participants`
--
ALTER TABLE `event_participants`
  MODIFY `participant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `funds`
--
ALTER TABLE `funds`
  MODIFY `fund_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `rank_history`
--
ALTER TABLE `rank_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `rank_verification_history`
--
ALTER TABLE `rank_verification_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registration_requests`
--
ALTER TABLE `registration_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `trainings`
--
ALTER TABLE `trainings`
  MODIFY `training_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `achievements`
--
ALTER TABLE `achievements`
  ADD CONSTRAINT `achievements_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `achievements_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_achievements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_achievements_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance_checkins`
--
ALTER TABLE `attendance_checkins`
  ADD CONSTRAINT `fk_checkin_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_checkin_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_checkin_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`) ON DELETE CASCADE;

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `fk_events_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_events_handler_id` FOREIGN KEY (`handler_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_events_previous_handler_id` FOREIGN KEY (`previous_handler_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_events_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_events_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `event_documents`
--
ALTER TABLE `event_documents`
  ADD CONSTRAINT `event_documents_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_event_documents_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_event_documents_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_event_documents_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `event_participants`
--
ALTER TABLE `event_participants`
  ADD CONSTRAINT `event_participants_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_participants_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_event_participants_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_event_participants_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_event_participants_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `funds`
--
ALTER TABLE `funds`
  ADD CONSTRAINT `fk_funds_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_funds_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `funds_ibfk_1` FOREIGN KEY (`related_event_id`) REFERENCES `events` (`event_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `funds_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `members`
--
ALTER TABLE `members`
  ADD CONSTRAINT `fk_members_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_members_rank_verified_by` FOREIGN KEY (`rank_verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_members_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_members_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `rank_history`
--
ALTER TABLE `rank_history`
  ADD CONSTRAINT `fk_rank_history_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rank_history_promoted_by` FOREIGN KEY (`promoted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `rank_verification_history`
--
ALTER TABLE `rank_verification_history`
  ADD CONSTRAINT `fk_rvh_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rvh_request` FOREIGN KEY (`request_id`) REFERENCES `registration_requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rvh_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `registration_requests`
--
ALTER TABLE `registration_requests`
  ADD CONSTRAINT `fk_registration_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_registration_rank_verified_by` FOREIGN KEY (`rank_verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_registration_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_registration_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `trainings`
--
ALTER TABLE `trainings`
  ADD CONSTRAINT `fk_trainings_coach_id` FOREIGN KEY (`coach_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_trainings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_trainings_previous_coach_id` FOREIGN KEY (`previous_coach_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_trainings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_trainings_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
