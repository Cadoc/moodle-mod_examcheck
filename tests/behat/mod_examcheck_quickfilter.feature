@mod @mod_examcheck @mod_examcheck_quickfilter @javascript
Feature: Quick-filter view switcher on the checking roster
  As an invigilator
  I need to quickly switch between "Not yet checked", "Checked" and "All students" views
  So that I can monitor attendance at a glance without losing my datafilter settings

  Background:
    Given the following "courses" exist:
      | fullname    | shortname |
      | Test Course | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Alice     | Smith    |
      | student2 | Bob       | Jones    |
      | student3 | Carol     | Brown    |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "activities" exist:
      | activity  | name     | course | idnumber   |
      | examcheck | Exam Day | C1     | examcheck1 |
    And the following mod_examcheck steps exist:
      | examcheck  | name       |
      | examcheck1 | Attendance |
    And I log in as "teacher1"
    And I am on the "Exam Day" "mod_examcheck activity" page

  Scenario: Quick filter tab buttons are visible on the checking dashboard
    Then I should see "Not yet checked"
    And I should see "Checked"
    And I should see "All students"
    And "All students" "button" should be visible

  Scenario: All students tab shows all enrolled students
    When I click on "All students" "button"
    Then I should see "Alice Smith"
    And I should see "Bob Jones"
    And I should see "Carol Brown"

  Scenario: Not yet checked tab restores the active checkstatus filter
    When I apply the "Attendance" check status filter set to "not checked" in the roster
    Then the "Not yet checked" quick filter tab should be active
    And I should see "Alice Smith"
    And I should see "Bob Jones"
    And I should see "Carol Brown"
    When I mark "Alice Smith" as checked on step "Attendance"
    Then I should not see "Alice Smith"
    And I should see "Bob Jones"
    And I should see "Carol Brown"

  Scenario: Checked tab shows the inverse of the active checkstatus filter
    Given I mark "Alice Smith" as checked on step "Attendance"
    And I apply the "Attendance" check status filter set to "not checked" in the roster
    When I click on "Checked" "button"
    Then I should see "Alice Smith"
    And I should not see "Bob Jones"
    And I should not see "Carol Brown"

  Scenario: All students tab suspends the checkstatus filter
    Given I mark "Alice Smith" as checked on step "Attendance"
    And I apply the "Attendance" check status filter set to "not checked" in the roster
    Then I should not see "Alice Smith"
    When I click on "All students" "button"
    Then I should see "Alice Smith"
    And I should see "Bob Jones"
    And I should see "Carol Brown"

  Scenario: Active tab syncs when the invigilator changes the datafilter chips
    When I apply the "Attendance" check status filter set to "not checked" in the roster
    Then the "Not yet checked" quick filter tab should be active
    When I clear all roster filters
    Then the "All students" quick filter tab should be active
