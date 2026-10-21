@core @core_message @javascript
Feature: Restore an unsent message
  In order to not lose a message I was writing
  As a user
  I need to be reminded of my unsent message after leaving the page

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
    And the following "message contacts" exist:
      | user     | contact  |
      | student1 | student2 |
    And the following config values are set as admin:
      | messaging         | 1 |
      | messagingallusers | 1 |

  Scenario: An unsent message is restored on the next page load
    Given I log in as "student1"
    And I open messaging
    And I select "Student 2" user in messaging
    And I set the field with xpath "//textarea[@data-region='send-message-txt']" to "My draft message"
    When I am on homepage
    Then I should see "You have an unsent message. It will be lost if you leave this page."
    And the field with xpath "//textarea[@data-region='send-message-txt']" matches value "My draft message"

  Scenario: No unsent message notification is shown without a draft
    Given I log in as "student1"
    When I am on homepage
    Then I should not see "You have an unsent message. It will be lost if you leave this page."
