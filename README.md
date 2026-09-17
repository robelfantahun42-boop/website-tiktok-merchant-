# Task Website

A complete task management system with user authentication, task completion, referral system, and admin panel.

## Features

- User registration and authentication
- Task completion with rewards
- Referral system with commission tracking
- Payment proof upload system
- Admin panel for user, task, and payment management
- Balance system with support for negative balances
- Notifications system

## Setup Instructions

### 1. Database Setup

1. Create a MySQL database named `taskdb`
2. Import the SQL schema from `sql/schema.sql`:
   ```bash
   mysql -u your_username -p taskdb < sql/schema.sql