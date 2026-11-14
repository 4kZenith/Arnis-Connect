-- Private training requests (public/private_trainings.php).
-- classes/PrivateTrainings.php creates this table automatically on first use;
-- running this file by hand is optional.

CREATE TABLE IF NOT EXISTS `private_training_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` time DEFAULT NULL,
  `preferred_coach_id` int(11) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('Pending','Awaiting Member','Confirmed','Rejected','Declined','Cancelled') NOT NULL DEFAULT 'Pending',
  `coach_id` int(11) DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT NULL,
  `session_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `decision_note` varchar(255) DEFAULT NULL,
  `training_id` int(11) DEFAULT NULL,
  `payment_status` enum('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
  `paid_at` datetime DEFAULT NULL,
  `fund_id` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `idx_ptr_member` (`member_id`, `status`),
  KEY `idx_ptr_coach` (`coach_id`, `status`),
  CONSTRAINT `fk_ptr_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
