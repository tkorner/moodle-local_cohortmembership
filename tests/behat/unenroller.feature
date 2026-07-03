@local @local_cohortmembership
Feature: Admins can unenroll users from cohorts
  In order to manage cohort enrolments
  As an admin
  I need to be able to unenroll users from cohorts

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
      | student1 | Student   | 2        | student2@example.com |
      | student1 | Student   | 3        | student3@example.com |
      | student1 | Student   | 4        | student4@example.com |

  Scenario: Admin can unenroll users from cohorts
    Given I am on the "My courses" page logged in as "admin"
