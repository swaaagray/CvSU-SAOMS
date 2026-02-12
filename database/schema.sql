-- Create database
CREATE DATABASE IF NOT EXISTS academic_org_db;
USE academic_org_db;

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NULL,
    email VARCHAR(100) UNIQUE NULL,
    role ENUM('mis_coordinator', 'osas', 'org_adviser', 'org_president', 'council_president', 'council_adviser') NOT NULL,
    google_id VARCHAR(255) UNIQUE NULL,
    oauth_provider ENUM('local', 'google', 'cvsu') DEFAULT 'local',
    osas_approved BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_google_id (google_id),
    INDEX idx_oauth_provider (oauth_provider)
);

-- Colleges table
CREATE TABLE colleges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- MIS Coordinators table
CREATE TABLE mis_coordinators (
    id INT PRIMARY KEY AUTO_INCREMENT,
    college_id INT NOT NULL,
    user_id INT NOT NULL,
	coordinator_name VARCHAR(255) NULL,
    academic_year_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
    UNIQUE KEY unique_college_academic_year (college_id, academic_year_id),
    INDEX idx_mis_coordinator_college (college_id),
    INDEX idx_mis_coordinator_academic_year (academic_year_id)
);

-- Council table
CREATE TABLE council (
    id INT PRIMARY KEY AUTO_INCREMENT,
    college_id INT,
    academic_year_id INT,
    council_name VARCHAR(255) NOT NULL,
    council_code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    logo_path VARCHAR(255),
    facebook_link VARCHAR(255),
    adviser_id INT,
    president_id INT,
    status ENUM('recognized', 'unrecognized') DEFAULT 'unrecognized',
    type ENUM('new','old') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    adviser_name VARCHAR(255),
    president_name VARCHAR(255),
    FOREIGN KEY (college_id) REFERENCES colleges(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
    FOREIGN KEY (adviser_id) REFERENCES users(id),
    FOREIGN KEY (president_id) REFERENCES users(id),
    INDEX idx_council_academic_year (academic_year_id)
);

-- Courses table
CREATE TABLE courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    college_id INT,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (college_id) REFERENCES colleges(id),
    UNIQUE KEY unique_course_code (college_id, code)
);

-- Organizations table
CREATE TABLE organizations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    org_name VARCHAR(255) NOT NULL,
    course_id INT,
    academic_year_id INT,
    description TEXT,
    college_id INT,
    logo_path VARCHAR(255),
    facebook_link VARCHAR(255),
    adviser_id INT,
    president_id INT,
    status ENUM('recognized', 'unrecognized') DEFAULT 'unrecognized',
    type ENUM('new','old') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    adviser_name VARCHAR(255),
    president_name VARCHAR(255),
    FOREIGN KEY (adviser_id) REFERENCES users(id),
    FOREIGN KEY (president_id) REFERENCES users(id),
    FOREIGN KEY (college_id) REFERENCES colleges(id),
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
    INDEX idx_organizations_academic_year (academic_year_id)
);

-- Organization Documents table (with approval workflow columns)
CREATE TABLE organization_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT,
    academic_year_id INT,
    document_type ENUM(
        'adviser_resume',
        'student_profile',
        'officers_list',
        'calendar_activities',
        'official_logo',
        'officers_grade',
        'group_picture',
        'constitution_bylaws',
        'members_list',
        'good_moral',
        'adviser_acceptance',
        'budget_resolution',
        'other'
    ) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    submitted_by INT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    rejection_reason TEXT,
    resubmission_deadline DATETIME NULL,
    deadline_set_by INT NULL,
    deadline_set_at TIMESTAMP NULL,
    resubmit_reason TEXT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    adviser_approved_at TIMESTAMP NULL,
    adviser_rejected_at TIMESTAMP NULL,
    osas_approved_at TIMESTAMP NULL,
    osas_rejected_at TIMESTAMP NULL,
    approved_by_adviser INT NULL,
    approved_by_osas INT NULL,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    FOREIGN KEY (deadline_set_by) REFERENCES users(id),
    FOREIGN KEY (approved_by_adviser) REFERENCES users(id),
    FOREIGN KEY (approved_by_osas) REFERENCES users(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_terms(id) ON DELETE SET NULL
);

-- Indexes for organization_documents
CREATE INDEX idx_org_docs_deadline ON organization_documents(resubmission_deadline);


-- Events table
CREATE TABLE events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT,
    council_id INT,
    semester_id INT,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    event_date DATETIME NOT NULL,
    venue VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (council_id) REFERENCES council(id),
    FOREIGN KEY (semester_id) REFERENCES academic_semesters(id) ON DELETE SET NULL,
    INDEX idx_events_semester (semester_id)
);

-- Event Approvals table
CREATE TABLE event_approvals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT,
    council_id INT,
    semester_id INT,
    title VARCHAR(100) NOT NULL,
    venue VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (council_id) REFERENCES council(id),
    FOREIGN KEY (semester_id) REFERENCES academic_semesters(id) ON DELETE SET NULL,
    INDEX idx_event_approvals_semester_id (semester_id)
);

-- Event Documents table
CREATE TABLE event_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_approval_id INT,
    document_type ENUM(
        'activity_proposal',
        'resolution_budget_approval',
        'letter_venue_equipment',
        'cv_speakers'
    ) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    submitted_by INT,
    status ENUM('pending', 'approved', 'rejected', 'sent_to_osas') DEFAULT 'pending',
    rejection_reason TEXT,
    resubmission_deadline DATETIME NULL,
    deadline_set_by INT NULL,
    deadline_set_at TIMESTAMP NULL,
    adviser_approved_at TIMESTAMP NULL,
    adviser_rejected_at TIMESTAMP NULL,
    osas_approved_at TIMESTAMP NULL,
    osas_rejected_at TIMESTAMP NULL,
    approved_by_adviser INT NULL,
    approved_by_osas INT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_approval_id) REFERENCES event_approvals(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    FOREIGN KEY (deadline_set_by) REFERENCES users(id),
    FOREIGN KEY (approved_by_adviser) REFERENCES users(id),
    FOREIGN KEY (approved_by_osas) REFERENCES users(id)
);

-- Indexes for event_documents
CREATE INDEX idx_event_documents_status ON event_documents(status);
CREATE INDEX idx_event_documents_adviser_approved_at ON event_documents(adviser_approved_at);
CREATE INDEX idx_event_documents_osas_approved_at ON event_documents(osas_approved_at);
CREATE INDEX idx_event_documents_event_approval_id ON event_documents(event_approval_id);
CREATE INDEX idx_event_docs_deadline ON event_documents(resubmission_deadline);

-- Event Images table
CREATE TABLE event_images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id)
);

-- Event Participants table
CREATE TABLE event_participants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT,
    name VARCHAR(100) NOT NULL,
    course VARCHAR(50),
    yearSection VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id)
);

-- Awards table
CREATE TABLE awards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT,
    council_id INT,
    semester_id INT,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    award_date DATE NOT NULL,
    venue VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (council_id) REFERENCES council(id),
    FOREIGN KEY (semester_id) REFERENCES academic_semesters(id) ON DELETE SET NULL,
    INDEX idx_awards_semester (semester_id)
);

-- Award Images table
CREATE TABLE award_images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    award_id INT,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (award_id) REFERENCES awards(id)
);

-- Award Participants table
CREATE TABLE award_participants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    award_id INT,
    name VARCHAR(100) NOT NULL,
    course VARCHAR(50),
    yearSection VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (award_id) REFERENCES awards(id)
);

-- Student Officials table
CREATE TABLE student_officials (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT,
    council_id INT,
    academic_year_id INT,
    name VARCHAR(100) NOT NULL,
    student_number VARCHAR(50) NOT NULL,
    course VARCHAR(50),
    year_section VARCHAR(50),
    position VARCHAR(100),
    picture_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (council_id) REFERENCES council(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
    INDEX idx_student_officials_academic_year (academic_year_id)
);

-- Adviser Officials table
CREATE TABLE adviser_officials (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT NULL,
    council_id INT NULL,
    academic_year_id INT NOT NULL,
    adviser_id INT NOT NULL,
    position VARCHAR(100) DEFAULT 'Adviser',
    picture_path VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign key constraints
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (council_id) REFERENCES council(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
    FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- Indexes for better performance
    INDEX idx_adviser_officials_organization (organization_id),
    INDEX idx_adviser_officials_council (council_id),
    INDEX idx_adviser_officials_academic_year (academic_year_id),
    INDEX idx_adviser_officials_adviser (adviser_id),
    INDEX idx_adviser_officials_created_at (created_at),
    
    -- Unique constraint to prevent duplicate adviser assignments
    UNIQUE KEY unique_adviser_assignment (organization_id, council_id, academic_year_id, adviser_id)
);

-- Financial Reports table
CREATE TABLE financial_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT,
    council_id INT,
    semester_id INT,
    title VARCHAR(100) NOT NULL,
    expenses DECIMAL(10,2) NOT NULL DEFAULT 0,
    revenue DECIMAL(10,2) NOT NULL DEFAULT 0,
    turnover DECIMAL(10,2) NOT NULL DEFAULT 0,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0,
    report_date DATE NOT NULL,
    file_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (council_id) REFERENCES council(id),
    FOREIGN KEY (semester_id) REFERENCES academic_semesters(id) ON DELETE SET NULL,
    INDEX idx_financial_reports_semester (semester_id)
);

-- Student Data table
CREATE TABLE student_data (
    id INT PRIMARY KEY AUTO_INCREMENT,
    organization_id INT NOT NULL,
    semester_id INT,
    student_name VARCHAR(255) NOT NULL,
    student_number VARCHAR(50) NOT NULL,
    course VARCHAR(100) NOT NULL,
    sex VARCHAR(10) NOT NULL,
    section VARCHAR(50) NOT NULL,
    org_status ENUM('Member', 'Non-Member') NOT NULL DEFAULT 'Non-Member',
    council_status ENUM('Active', 'Inactive', 'Suspended', 'Graduated') NOT NULL DEFAULT 'Active',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES academic_semesters(id) ON DELETE SET NULL,
    INDEX idx_student_data_semester (semester_id)
);

-- Academic Terms table (Academic Year Management)
CREATE TABLE academic_terms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_year VARCHAR(9) NOT NULL UNIQUE, -- Format: YYYY-YYYY
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    document_start_date DATE NOT NULL, -- Recognition eligibility start date
    document_end_date DATE NOT NULL,   -- Recognition eligibility end date
    recognition_validity ENUM('automatic', 'manual') DEFAULT 'automatic',
    status ENUM('inactive', 'active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_school_year (school_year),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    CONSTRAINT chk_academic_year_dates CHECK (start_date < end_date),
    CONSTRAINT chk_document_dates CHECK (document_start_date <= document_end_date),
    CONSTRAINT chk_document_within_academic_year CHECK (
        document_start_date >= start_date AND 
        document_end_date <= end_date
    )
);

-- Academic Semesters table (Semester Management within Academic Year)
CREATE TABLE academic_semesters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    academic_term_id INT NOT NULL,
    semester ENUM('1st', '2nd') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('inactive', 'active', 'archived') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
    UNIQUE KEY unique_semester_per_term (academic_term_id, semester),
    INDEX idx_semester_status (status),
    INDEX idx_semester_dates_status (start_date, end_date, status),
    CONSTRAINT chk_semester_dates CHECK (start_date < end_date),
    CONSTRAINT chk_semester_within_academic_year CHECK (
        start_date >= (SELECT start_date FROM academic_terms WHERE id = academic_term_id) AND
        end_date <= (SELECT end_date FROM academic_terms WHERE id = academic_term_id)
    )
); 

-- Council Documents table (with approval workflow columns)
CREATE TABLE council_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    council_id INT NOT NULL,
    academic_year_id INT,
    document_type ENUM(
        'adviser_resume',
        'student_profile',
        'officers_list',
        'calendar_activities',
        'official_logo',
        'officers_grade',
        'group_picture',
        'constitution_bylaws',
        'members_list',
        'good_moral',
        'adviser_acceptance',
        'budget_resolution',
        'other'
    ) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    submitted_by INT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reject_reason TEXT,
    resubmission_deadline DATETIME NULL,
    deadline_set_by INT NULL,
    deadline_set_at TIMESTAMP NULL,
    resubmit_reason TEXT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    adviser_approval_at TIMESTAMP NULL,
    adviser_rejected_at TIMESTAMP NULL,
    osas_approved_at TIMESTAMP NULL,
    osas_rejected_at TIMESTAMP NULL,
    approved_by_adviser INT NULL,
    approved_by_osas INT NULL,
    FOREIGN KEY (council_id) REFERENCES council(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    FOREIGN KEY (deadline_set_by) REFERENCES users(id),
    FOREIGN KEY (approved_by_adviser) REFERENCES users(id),
    FOREIGN KEY (approved_by_osas) REFERENCES users(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_terms(id) ON DELETE SET NULL
); 

-- Indexes for council_documents
CREATE INDEX idx_council_documents_council_id ON council_documents(council_id);
CREATE INDEX idx_council_documents_status ON council_documents(status);
CREATE INDEX idx_council_documents_document_type ON council_documents(document_type);
CREATE INDEX idx_council_documents_submitted_at ON council_documents(submitted_at);
CREATE INDEX idx_council_docs_deadline ON council_documents(resubmission_deadline);

-- System Settings table (for dynamic configuration values)
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
);

-- Insert default Activity Permit officials
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('permit_recommending_name', 'DENMARK A. GARCIA', 'Activity Permit - Recommending Approval Name'),
    ('permit_recommending_position', 'Head, SDS', 'Activity Permit - Recommending Approval Position'),
    ('permit_approved_name', 'SHARON M. ISIP', 'Activity Permit - Approved By Name'),
    ('permit_approved_position', 'Dean, OSAS', 'Activity Permit - Approved By Position');

-- Notifications tables
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM(
        'document_approved', 'document_rejected', 
        'event_approved', 'event_rejected', 
        'document_submitted', 'event_submitted',
        'documents_for_review', 'event_documents_for_review',
        'event_document_approved', 'event_document_rejected',
        'general'
    ) NOT NULL,
    related_id INT NULL,
    related_type VARCHAR(50) NULL,
    academic_year_id INT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_created_at (created_at),
    INDEX idx_notifications_academic_year_id (academic_year_id)
);



-- Triggers to keep type in sync with recognition status (DISABLED: type flips only at academic year transition)
DELIMITER $$

-- DROP TRIGGER IF EXISTS council_set_old_on_recognized $$
-- CREATE TRIGGER council_set_old_on_recognized
-- BEFORE UPDATE ON council
-- FOR EACH ROW
-- BEGIN
--     IF NEW.status = 'recognized' AND (OLD.status <> 'recognized' OR OLD.status IS NULL) THEN
--         SET NEW.type = 'old';
--     END IF;
-- END $$

-- DROP TRIGGER IF EXISTS organizations_set_old_on_recognized $$
-- CREATE TRIGGER organizations_set_old_on_recognized
-- BEFORE UPDATE ON organizations
-- FOR EACH ROW
-- BEGIN
--     IF NEW.status = 'recognized' AND (OLD.status <> 'recognized' OR OLD.status IS NULL) THEN
--         SET NEW.type = 'old';
--     END IF;
-- END $$

-- Clear names when academic_year_id is set to NULL
DROP TRIGGER IF EXISTS organizations_clear_names_on_year_null $$
CREATE TRIGGER organizations_clear_names_on_year_null
BEFORE UPDATE ON organizations
FOR EACH ROW
BEGIN
    IF NEW.academic_year_id IS NULL AND (OLD.academic_year_id IS NOT NULL) THEN
        SET NEW.adviser_name = NULL,
            NEW.president_name = NULL;
    END IF;
END $$

DROP TRIGGER IF EXISTS council_clear_names_on_year_null $$
CREATE TRIGGER council_clear_names_on_year_null
BEFORE UPDATE ON council
FOR EACH ROW
BEGIN
    IF NEW.academic_year_id IS NULL AND (OLD.academic_year_id IS NOT NULL) THEN
        SET NEW.adviser_name = NULL,
            NEW.president_name = NULL;
    END IF;
END $$

DROP TRIGGER IF EXISTS mis_coordinators_clear_name_on_year_null $$
CREATE TRIGGER mis_coordinators_clear_name_on_year_null
BEFORE UPDATE ON mis_coordinators
FOR EACH ROW
BEGIN
    IF NEW.academic_year_id IS NULL AND (OLD.academic_year_id IS NOT NULL) THEN
        SET NEW.coordinator_name = NULL;
    END IF;
END $$

-- Clear non-OSAS user emails when academic_year_id is nulled via org/council/mis reset
DROP TRIGGER IF EXISTS organizations_clear_user_emails_on_year_null $$
CREATE TRIGGER organizations_clear_user_emails_on_year_null
AFTER UPDATE ON organizations
FOR EACH ROW
BEGIN
    IF NEW.academic_year_id IS NULL AND (OLD.academic_year_id IS NOT NULL) THEN
        UPDATE users SET email = NULL WHERE id IN (NEW.adviser_id, NEW.president_id) AND role != 'osas';
    END IF;
END $$

DROP TRIGGER IF EXISTS council_clear_user_emails_on_year_null $$
CREATE TRIGGER council_clear_user_emails_on_year_null
AFTER UPDATE ON council
FOR EACH ROW
BEGIN
    IF NEW.academic_year_id IS NULL AND (OLD.academic_year_id IS NOT NULL) THEN
        UPDATE users SET email = NULL WHERE id IN (NEW.adviser_id, NEW.president_id) AND role != 'osas';
    END IF;
END $$

DROP TRIGGER IF EXISTS mis_coordinators_clear_user_emails_on_year_null $$
CREATE TRIGGER mis_coordinators_clear_user_emails_on_year_null
AFTER UPDATE ON mis_coordinators
FOR EACH ROW
BEGIN
    IF NEW.academic_year_id IS NULL AND (OLD.academic_year_id IS NOT NULL) THEN
        UPDATE users SET email = NULL WHERE id = NEW.user_id AND role != 'osas';
    END IF;
END $$

DELIMITER ;

-- Organization Applications table (pending submissions awaiting OSAS review)
CREATE TABLE organization_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    application_type ENUM('organization', 'council') NOT NULL,
    -- Organization fields (NULL for council applications)
    org_code VARCHAR(20) NULL,
    org_name VARCHAR(255) NULL,
    course_id INT NULL,
    -- Common fields
    college_id INT NOT NULL,
    president_name VARCHAR(255) NOT NULL,
    president_email VARCHAR(100) NOT NULL,
    adviser_name VARCHAR(255) NOT NULL,
    adviser_email VARCHAR(100) NOT NULL,
    -- Verification info
    verified_by ENUM('president', 'adviser') NOT NULL,
    verified_email VARCHAR(100) NOT NULL,
    -- Application status
    status ENUM('pending_review', 'approved', 'rejected') DEFAULT 'pending_review',
    rejection_reason TEXT NULL,
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (college_id) REFERENCES colleges(id),
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_application_type (application_type),
    INDEX idx_created_at (created_at)
);

-- Email OTP Verification table (temporary storage for OTP codes)
CREATE TABLE email_verifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    form_data JSON NOT NULL,
    verified_by ENUM('president', 'adviser') NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_otp (email, otp_code),
    INDEX idx_expires (expires_at)
);