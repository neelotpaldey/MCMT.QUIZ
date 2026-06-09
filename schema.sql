-- developed by @neelotpal.dey
-- =============================================
-- ONLINE EXAMINATION SYSTEM - DATABASE SCHEMA
-- =============================================

CREATE DATABASE IF NOT EXISTS exam_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE exam_system;

-- Admin Users
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(200),
    email VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Students
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(200) NOT NULL,
    mobile VARCHAR(15) NOT NULL UNIQUE,
    dob DATE NOT NULL,
    email VARCHAR(200),
    roll_number VARCHAR(50) UNIQUE,
    photo VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Exams
CREATE TABLE IF NOT EXISTS exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    duration_minutes INT NOT NULL DEFAULT 30,
    total_marks INT NOT NULL,
    passing_marks INT NOT NULL,
    marks_per_correct DECIMAL(5,2) DEFAULT 2.00,
    negative_marks DECIMAL(5,2) DEFAULT 0.00,
    total_questions INT NOT NULL DEFAULT 25,
    gk_questions INT DEFAULT 0,
    english_questions INT DEFAULT 0,
    logical_questions INT DEFAULT 0,
    question_source ENUM('bank','gemini','groq') DEFAULT 'bank',
    api_key VARCHAR(500),
    api_model VARCHAR(100),
    instructions TEXT,
    show_results TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) DEFAULT 0,
    is_started TINYINT(1) DEFAULT 0,
    started_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES admin_users(id)
);

-- Question Bank (stored questions)
CREATE TABLE IF NOT EXISTS question_bank (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category ENUM('gk','english','logical') NOT NULL,
    question_text TEXT NOT NULL,
    option_a VARCHAR(500) NOT NULL,
    option_b VARCHAR(500) NOT NULL,
    option_c VARCHAR(500) NOT NULL,
    option_d VARCHAR(500) NOT NULL,
    correct_answer ENUM('A','B','C','D') NOT NULL,
    explanation TEXT,
    difficulty ENUM('easy','medium','hard') DEFAULT 'medium',
    exam_id INT NULL COMMENT 'NULL = global bank, else exam-specific',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Student Exam Sessions (unique question set per student per exam)
CREATE TABLE IF NOT EXISTS student_exam_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    question_order TEXT COMMENT 'JSON array of question IDs in order',
    started_at TIMESTAMP NULL,
    submitted_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    ip_address VARCHAR(50),
    UNIQUE KEY unique_session (student_id, exam_id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (exam_id) REFERENCES exams(id)
);

-- Student Answers
CREATE TABLE IF NOT EXISTS student_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_answer ENUM('A','B','C','D') NULL,
    is_marked_review TINYINT(1) DEFAULT 0,
    time_spent INT DEFAULT 0 COMMENT 'seconds',
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_answer (session_id, question_id),
    FOREIGN KEY (session_id) REFERENCES student_exam_sessions(id)
);

-- Results
CREATE TABLE IF NOT EXISTS exam_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL UNIQUE,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    total_questions INT,
    attempted INT DEFAULT 0,
    correct INT DEFAULT 0,
    wrong INT DEFAULT 0,
    skipped INT DEFAULT 0,
    marks_obtained DECIMAL(8,2) DEFAULT 0,
    percentage DECIMAL(5,2) DEFAULT 0,
    is_passed TINYINT(1) DEFAULT 0,
    rank_in_exam INT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES student_exam_sessions(id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (exam_id) REFERENCES exams(id)
);

-- AI Generated Questions Cache (per session)
CREATE TABLE IF NOT EXISTS ai_generated_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_text TEXT NOT NULL,
    option_a VARCHAR(500) NOT NULL,
    option_b VARCHAR(500) NOT NULL,
    option_c VARCHAR(500) NOT NULL,
    option_d VARCHAR(500) NOT NULL,
    correct_answer ENUM('A','B','C','D') NOT NULL,
    category ENUM('gk','english','logical') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES student_exam_sessions(id)
);

-- =============================================
-- DEFAULT DATA
-- =============================================

-- App Settings (key-value store)
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('show_student_results', '1'),
('groq_api_key', ''),
('groq_api_model', 'llama-3.1-8b-instant'),
('gemini_api_key', ''),
('gemini_api_model', 'gemini-2.5-flash'),
('openai_api_key', ''),
('openai_api_model', 'gpt-4o-mini');

-- Default Admin (password: admin123)
INSERT IGNORE INTO admin_users (username, password, full_name, email) VALUES 
('admin', '$2y$10$T5g7hAhWIPvqXVd04lDUKemDYrQyX.PXAbj2oYN3Dh7PzFS6pTfJm', 'System Administrator', 'admin@examportal.com');

-- Sample Question Bank (GK - 30 questions)
INSERT IGNORE INTO question_bank (category, question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty) VALUES
('gk', 'Who is known as the Father of the Nation in India?', 'Jawaharlal Nehru', 'Mahatma Gandhi', 'Subhash Chandra Bose', 'Bhagat Singh', 'B', 'easy'),
('gk', 'Which is the largest planet in our Solar System?', 'Saturn', 'Uranus', 'Jupiter', 'Neptune', 'C', 'easy'),
('gk', 'In which year did India gain independence?', '1945', '1947', '1950', '1948', 'B', 'easy'),
('gk', 'What is the capital of Australia?', 'Sydney', 'Melbourne', 'Canberra', 'Brisbane', 'C', 'medium'),
('gk', 'Which gas is most abundant in Earth\'s atmosphere?', 'Oxygen', 'Carbon Dioxide', 'Hydrogen', 'Nitrogen', 'D', 'easy'),
('gk', 'Who painted the Mona Lisa?', 'Michelangelo', 'Leonardo da Vinci', 'Raphael', 'Donatello', 'B', 'easy'),
('gk', 'What is the currency of Japan?', 'Yuan', 'Won', 'Yen', 'Ringgit', 'C', 'easy'),
('gk', 'Which is the longest river in the world?', 'Amazon', 'Yangtze', 'Nile', 'Mississippi', 'C', 'easy'),
('gk', 'How many bones are in the adult human body?', '196', '206', '216', '226', 'B', 'medium'),
('gk', 'What is the chemical symbol for Gold?', 'Go', 'Gd', 'Au', 'Ag', 'C', 'medium'),
('gk', 'Which country has the largest population?', 'India', 'China', 'USA', 'Indonesia', 'B', 'easy'),
('gk', 'What is the speed of light in vacuum (approx)?', '3×10^6 m/s', '3×10^8 m/s', '3×10^10 m/s', '3×10^4 m/s', 'B', 'medium'),
('gk', 'Who invented the telephone?', 'Thomas Edison', 'Nikola Tesla', 'Alexander Graham Bell', 'Guglielmo Marconi', 'C', 'easy'),
('gk', 'Which is the smallest continent?', 'Europe', 'Antarctica', 'Australia', 'South America', 'C', 'easy'),
('gk', 'What is the national bird of India?', 'Sparrow', 'Eagle', 'Peacock', 'Flamingo', 'C', 'easy'),
('gk', 'Which planet is known as the Red Planet?', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'B', 'easy'),
('gk', 'The Great Wall of China was built during which dynasty?', 'Han', 'Ming', 'Tang', 'Qing', 'B', 'hard'),
('gk', 'Which ocean is the largest?', 'Atlantic', 'Indian', 'Arctic', 'Pacific', 'D', 'easy'),
('gk', 'What is the SI unit of electric current?', 'Volt', 'Watt', 'Ampere', 'Ohm', 'C', 'medium'),
('gk', 'Who wrote "Romeo and Juliet"?', 'Charles Dickens', 'William Shakespeare', 'Jane Austen', 'Mark Twain', 'B', 'easy'),

-- English Questions (30)
('english', 'Choose the correct spelling:', 'Accomodate', 'Acommodate', 'Accommodate', 'Accomodate', 'C', 'medium'),
('english', 'What is the antonym of "ancient"?', 'Old', 'Modern', 'Aged', 'Historic', 'B', 'easy'),
('english', 'Fill in the blank: She _____ to the market yesterday.', 'go', 'goes', 'went', 'going', 'C', 'easy'),
('english', 'Identify the noun in: "The beautiful flower bloomed in spring."', 'Beautiful', 'Bloomed', 'Flower', 'In', 'C', 'easy'),
('english', 'Choose the correct form: "Neither of the students _____ present."', 'were', 'are', 'was', 'have been', 'C', 'medium'),
('english', 'What does the idiom "break the ice" mean?', 'To shatter something', 'To begin conversation', 'To cause trouble', 'To feel cold', 'B', 'medium'),
('english', 'Which sentence uses the passive voice?', 'He ate the cake.', 'She is writing a letter.', 'The cake was eaten by him.', 'They play cricket.', 'C', 'medium'),
('english', 'What is the plural of "child"?', 'Childs', 'Children', 'Childen', 'Childrens', 'B', 'easy'),
('english', 'Choose the correct preposition: "She is good _____ mathematics."', 'in', 'at', 'on', 'for', 'B', 'medium'),
('english', 'What is a synonym of "diligent"?', 'Lazy', 'Careless', 'Hardworking', 'Slow', 'C', 'easy'),
('english', 'Identify the adjective: "The tall man walked quickly."', 'Man', 'Walked', 'Tall', 'Quickly', 'C', 'easy'),
('english', '"I have been working here for five years." This sentence is in which tense?', 'Simple Past', 'Past Perfect', 'Present Perfect Continuous', 'Future Perfect', 'C', 'hard'),
('english', 'Choose the correct article: "She is _____ honest person."', 'a', 'an', 'the', 'no article needed', 'B', 'medium'),
('english', 'What is the meaning of the prefix "mis-" in "misunderstand"?', 'Again', 'Wrongly', 'Before', 'Against', 'B', 'medium'),
('english', '"His bark is worse than his bite" means:', 'He is a dog', 'He is less harmful than he seems', 'He is dangerous', 'He speaks loudly', 'B', 'hard'),
('english', 'Which is a compound sentence?', 'She sang.', 'She sang because she was happy.', 'She sang and he danced.', 'Although she sang, she was nervous.', 'C', 'medium'),
('english', 'What is the comparative form of "good"?', 'Gooder', 'More good', 'Better', 'Best', 'C', 'easy'),
('english', 'Choose the correct sentence:', 'He don\'t know the answer.', 'He doesn\'t knows the answer.', 'He doesn\'t know the answer.', 'He not know the answer.', 'C', 'easy'),
('english', 'What part of speech is "quickly" in "He ran quickly"?', 'Adjective', 'Noun', 'Verb', 'Adverb', 'D', 'easy'),
('english', '"To burn the midnight oil" means to:', 'Light a fire', 'Work late at night', 'Waste resources', 'Feel sleepy', 'B', 'medium'),

-- Logical Reasoning Questions (30)
('logical', 'If all roses are flowers and all flowers are plants, then:', 'All plants are roses', 'All roses are plants', 'Some plants are not flowers', 'All flowers are roses', 'B', 'easy'),
('logical', 'What comes next in the series: 2, 4, 8, 16, ___?', '24', '30', '32', '64', 'C', 'easy'),
('logical', 'If BOOK is coded as ERRN, how is GOOD coded?', 'JRRG', 'JOOD', 'JRRD', 'GRRD', 'A', 'medium'),
('logical', 'A is taller than B. C is shorter than A. D is taller than C but shorter than B. Who is the tallest?', 'A', 'B', 'C', 'D', 'A', 'medium'),
('logical', 'Find the odd one out: Apple, Mango, Carrot, Banana', 'Apple', 'Mango', 'Carrot', 'Banana', 'C', 'easy'),
('logical', 'If 5 workers can build a wall in 20 days, how many days will 10 workers take?', '5', '10', '15', '40', 'B', 'easy'),
('logical', 'Complete the analogy: Doctor : Hospital :: Teacher : ?', 'Book', 'School', 'Student', 'Pen', 'B', 'easy'),
('logical', 'What is the next number: 1, 1, 2, 3, 5, 8, ___?', '11', '13', '16', '12', 'B', 'medium'),
('logical', 'If Sunday is day 1, what day is the 25th day?', 'Saturday', 'Sunday', 'Monday', 'Tuesday', 'C', 'hard'),
('logical', 'A clock shows 3:15. What is the angle between hour and minute hands?', '0°', '7.5°', '15°', '22.5°', 'B', 'hard'),
('logical', 'Find missing: 3, 6, 11, 18, 27, ___', '36', '38', '40', '45', 'B', 'medium'),
('logical', 'If PENCIL = 6, PAPER = 5, then NOTEBOOK = ?', '7', '8', '9', '10', 'B', 'easy'),
('logical', 'Pointing to a woman, Ram says "She is the daughter of my grandfather\'s only son." How is she related to Ram?', 'Sister', 'Aunt', 'Cousin', 'Mother', 'A', 'medium'),
('logical', 'How many triangles are in a regular hexagon divided by all diagonals?', '6', '12', '18', '24', 'C', 'hard'),
('logical', 'If North becomes West, South becomes East, then what does East become?', 'North', 'South', 'West', 'Northeast', 'A', 'medium'),
('logical', 'A train 100m long passes a pole in 10 seconds. Its speed is:', '10 m/s', '1000 m/s', '36 km/h', '72 km/h', 'C', 'medium'),
('logical', 'Choose the figure that completes the pattern: △ ○ □ △ ○ ?', '△', '○', '□', '◇', 'C', 'easy'),
('logical', 'If you rearrange CIFAIPC, you get a name of:', 'Country', 'Ocean', 'State', 'City', 'B', 'hard'),
('logical', 'Water is to Thirst as Food is to:', 'Eat', 'Cook', 'Hunger', 'Taste', 'C', 'easy'),
('logical', '6 people shake hands with each other once. Total handshakes?', '12', '15', '18', '30', 'B', 'medium');
