@mod @mod_examcheck @mod_examcheck_quicksearch @javascript
Feature: Quick search on the checking roster
  As an invigilator
  I need to quickly find a student by typing part of their name
  So that I can locate and check them without scrolling through the full roster

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

  Scenario: The quick search box is visible on the checking dashboard
    Then I should see "Alice Smith"
    And I should see "Bob Jones"
    And I should see "Carol Brown"
    And ".examcheck-roster" "css_element" should exist

  Scenario: Typing a name filters the roster live
    When I type "Smith" into the roster quick search
    Then I should see "Alice Smith"
    And I should not see "Bob Jones"
    And I should not see "Carol Brown"

  Scenario: Search is case-insensitive
    When I type "smith" into the roster quick search
    Then I should see "Alice Smith"
    And I should not see "Bob Jones"

  Scenario: Clearing the search restores the full roster
    Given I type "Smith" into the roster quick search
    And I should not see "Bob Jones"
    When I type "" into the roster quick search
    Then I should see "Alice Smith"
    And I should see "Bob Jones"
    And I should see "Carol Brown"

  Scenario: Search matches partial names anywhere in the row, not just the start
    When I type "one" into the roster quick search
    Then I should see "Bob Jones"
    And I should not see "Alice Smith"
    And I should not see "Carol Brown"

  Scenario: Search does not match against the step toggle button labels
    # "Checked" / "Not checked" are the sr-only labels on every toggle button;
    # searching for that text must not match every row.
    When I type "checked" into the roster quick search
    Then I should not see "Alice Smith"
    And I should not see "Bob Jones"
    And I should not see "Carol Brown"
