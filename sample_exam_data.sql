-- developed by @neelotpal.dey

-- SAMPLE DATA FOR ONLINE EXAMINATION SYSTEM

USE exam_system;

-- STUDENTS
INSERT INTO students (full_name,mobile,dob,email,roll_number,photo,is_active) VALUES
('Rahul Sharma','9876543210','2004-05-12','rahul@example.com','BCA001','rahul.jpg',1),
('Priya Singh','9876543211','2003-08-21','priya@example.com','BCA002','priya.jpg',1),
('Amit Kumar','9876543212','2004-01-15','amit@example.com','BCA003','amit.jpg',1),
('Sneha Verma','9876543213','2003-11-09','sneha@example.com','BCA004','sneha.jpg',1),
('Rohit Gupta','9876543214','2004-03-25','rohit@example.com','BCA005','rohit.jpg',1);

-- EXAMS
INSERT INTO exams
(title,description,duration_minutes,total_marks,passing_marks,marks_per_correct,negative_marks,total_questions,gk_questions,english_questions,logical_questions,question_source,instructions,is_active,is_started,created_by)
VALUES
('BCA Entrance Mock Test','Demo examination for students',30,50,20,2,0.5,25,10,8,7,'bank','Read instructions carefully before starting.',1,1,1);

-- STUDENT EXAM SESSIONS
INSERT INTO student_exam_sessions
(student_id,exam_id,question_order,started_at,submitted_at,is_active,ip_address)
VALUES
(1,1,'[1,2,3,4,5,21,22,23,41,42]','2026-06-01 10:00:00','2026-06-01 10:25:00',0,'192.168.1.10'),
(2,1,'[1,2,3,4,5,21,22,23,41,42]','2026-06-01 10:02:00','2026-06-01 10:28:00',0,'192.168.1.11'),
(3,1,'[1,2,3,4,5,21,22,23,41,42]','2026-06-01 10:03:00',NULL,1,'192.168.1.12');

-- STUDENT ANSWERS
INSERT INTO student_answers
(session_id,question_id,selected_answer,is_marked_review,time_spent)
VALUES
(1,1,'B',0,30),
(1,2,'C',0,25),
(1,3,'B',0,20),
(1,21,'C',1,40),
(2,1,'B',0,15),
(2,2,'A',0,18),
(2,21,'C',0,22),
(3,1,'B',0,12);

-- RESULTS
INSERT INTO exam_results
(session_id,student_id,exam_id,total_questions,attempted,correct,wrong,skipped,marks_obtained,percentage,is_passed,rank_in_exam)
VALUES
(1,1,1,25,25,21,4,0,40.00,80.00,1,1),
(2,2,1,25,23,18,5,2,34.00,68.00,1,2);

-- AI GENERATED QUESTIONS
INSERT INTO ai_generated_questions
(session_id,question_text,option_a,option_b,option_c,option_d,correct_answer,category)
VALUES
(3,'Which data structure follows FIFO?','Stack','Queue','Tree','Graph','B','logical'),
(3,'Choose the correct spelling.','Recieve','Receive','Receeve','Receve','B','english'),
(3,'Capital of Uttar Pradesh?','Lucknow','Kanpur','Agra','Varanasi','A','gk');
