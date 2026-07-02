@mod @mod_examcheck @mod_examcheck_uncheck @javascript
Feature: Uncheck documentation, student flags and step exemptions
  As an invigilator
  I need to document why I removed a check, see flagged students, and see exempt steps
  So that there is an accurate, accountable record of the checking session

  Background:
    Given the following "courses" exist:
      | fullname    | shortname |
      | Test Course | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Alice     | Smith    |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity  | name     | course | idnumber   |
      | examcheck | Exam Day | C1     | examcheck1 |
    And the following mod_examcheck steps exist:
      | examcheck  | name       |
      | examcheck1 | Attendance |
    And I log in as "teacher1"
    And I am on the "Exam Day" "mod_examcheck activity" page
    And I mark "Alice Smith" as checked on step "Attendance"

  Scenario: Unchecking a step with no documentation required needs no dialog
    Given the "Attendance" step has uncheck mode "none"
    When I click on the "Attendance" toggle for "Alice Smith"
    Then ".mod_examcheck-uncheck-dialog" "css_element" should not exist
    And the "Attendance" toggle for "Alice Smith" should be unchecked

  Scenario: Unchecking with optional documentation can be confirmed without a reason
    Given the "Attendance" step has uncheck mode "optional"
    When I click on the "Attendance" toggle for "Alice Smith"
    Then I should see "Optionally document why this check is being removed."
    When I click on "Confirm removal" "button"
    Then the "Attendance" toggle for "Alice Smith" should be unchecked

  Scenario: Unchecking with mandatory documentation blocks confirmation until a reason is given
    Given the "Attendance" step has uncheck mode "mandatory"
    When I click on the "Attendance" toggle for "Alice Smith"
    Then I should see "A reason is required before this check can be removed."
    When I click on "Confirm removal" "button"
    Then I should see "Please select a reason or enter a note before confirming."
    And the "Attendance" toggle for "Alice Smith" should be checked
    When I set the field "Reason for removal" to "Marked in error"
    And I click on "Confirm removal" "button"
    Then the "Attendance" toggle for "Alice Smith" should be unchecked

  Scenario: Cancelling the uncheck dialog leaves the student checked
    Given the "Attendance" step has uncheck mode "mandatory"
    When I click on the "Attendance" toggle for "Alice Smith"
    And I click on "Cancel" "button"
    Then the "Attendance" toggle for "Alice Smith" should be checked

  Scenario: A flagged student shows a flag indicator in the roster
    Given student "Alice Smith" is flagged as "malpractice" with note "Observed copying" in "Exam Day"
    When I reload the page
    Then I should see "This student has been flagged"

  Scenario: An exempted student's step toggle is shown as exempt and disabled
    Given student "Alice Smith" is exempt from step "Attendance" in "Exam Day"
    When I reload the page
    Then the "Attendance" toggle for "Alice Smith" should be exempt
