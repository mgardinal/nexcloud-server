<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\Tests\unit\CalDAV;

use Generator;
use OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Calendar;
use OCA\DAV\CalDAV\CalendarImpl;
use OCA\DAV\CalDAV\InvitationResponse\InvitationResponseServer;
use OCA\DAV\CalDAV\Schedule\Plugin;
use OCA\DAV\Connector\Sabre\Server;
use OCP\Calendar\Exceptions\CalendarException;
use PHPUnit\Framework\MockObject\MockObject;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

class CalendarImplTest extends \Test\TestCase {
	private Calendar&MockObject $calendar;
	private array $calendarInfo;
	private CalDavBackend&MockObject $backend;
	private CalendarImpl $calendarImpl;
	private array $mockExportCollection;

	protected function setUp(): void {
		parent::setUp();

		$this->calendar = $this->createMock(Calendar::class);
		$this->calendarInfo = [
			'id' => 1,
			'{DAV:}displayname' => 'user readable name 123',
			'{http://apple.com/ns/ical/}calendar-color' => '#AABBCC',
			'uri' => '/this/is/a/uri',
			'principaluri' => 'principal/users/foobar'
		];
		$this->backend = $this->createMock(CalDavBackend::class);

		$this->calendarImpl = new CalendarImpl(
			$this->calendar,
			$this->calendarInfo,
			$this->backend
		);
	}


	public function testGetKey(): void {
		$this->assertEquals($this->calendarImpl->getKey(), 1);
	}

	public function testGetDisplayname(): void {
		$this->assertEquals($this->calendarImpl->getDisplayName(), 'user readable name 123');
	}

	public function testGetDisplayColor(): void {
		$this->assertEquals($this->calendarImpl->getDisplayColor(), '#AABBCC');
	}

	public function testSearch(): void {
		$this->backend->expects($this->once())
			->method('search')
			->with($this->calendarInfo, 'abc', ['def'], ['ghi'], 42, 1337)
			->willReturn(['SEARCHRESULTS']);

		$result = $this->calendarImpl->search('abc', ['def'], ['ghi'], 42, 1337);
		$this->assertEquals($result, ['SEARCHRESULTS']);
	}

	public function testGetPermissionRead(): void {
		$this->calendar->expects($this->once())
			->method('getACL')
			->with()
			->willReturn([
				['privilege' => '{DAV:}read', 'principal' => 'principal/users/foobar'],
				['privilege' => '{DAV:}read', 'principal' => 'principal/users/other'],
				['privilege' => '{DAV:}write', 'principal' => 'principal/users/other'],
				['privilege' => '{DAV:}all', 'principal' => 'principal/users/other'],
			]);

		$this->assertEquals(1, $this->calendarImpl->getPermissions());
	}

	public function testGetPermissionWrite(): void {
		$this->calendar->expects($this->once())
			->method('getACL')
			->with()
			->willReturn([
				['privilege' => '{DAV:}write', 'principal' => 'principal/users/foobar'],
				['privilege' => '{DAV:}read', 'principal' => 'principal/users/other'],
				['privilege' => '{DAV:}all', 'principal' => 'principal/users/other'],
			]);

		$this->assertEquals(6, $this->calendarImpl->getPermissions());
	}

	public function testGetPermissionReadWrite(): void {
		$this->calendar->expects($this->once())
			->method('getACL')
			->with()
			->willReturn([
				['privilege' => '{DAV:}write', 'principal' => 'principal/users/foobar'],
				['privilege' => '{DAV:}read', 'principal' => 'principal/users/foobar'],
				['privilege' => '{DAV:}all', 'principal' => 'principal/users/other'],
			]);

		$this->assertEquals(7, $this->calendarImpl->getPermissions());
	}

	public function testGetPermissionAll(): void {
		$this->calendar->expects($this->once())
			->method('getACL')
			->with()
			->willReturn([
				['privilege' => '{DAV:}all', 'principal' => 'principal/users/foobar'],
			]);

		$this->assertEquals(31, $this->calendarImpl->getPermissions());
	}

	public function testHandleImipMessage(): void {
		$message = <<<EOF
BEGIN:VCALENDAR
PRODID:-//Nextcloud/Nextcloud CalDAV Server//EN
METHOD:REPLY
VERSION:2.0
BEGIN:VEVENT
ATTENDEE;PARTSTAT=ACCEPTED:mailto:lewis@stardew-tent-living.com
ORGANIZER:mailto:pierre@generalstore.com
UID:aUniqueUid
SEQUENCE:2
REQUEST-STATUS:2.0;Success
END:VEVENT
END:VCALENDAR
EOF;

		/** @var CustomPrincipalPlugin|MockObject $authPlugin */
		$authPlugin = $this->createMock(CustomPrincipalPlugin::class);
		$authPlugin->expects(self::once())
			->method('setCurrentPrincipal')
			->with($this->calendar->getPrincipalURI());

		/** @var \Sabre\DAVACL\Plugin|MockObject $aclPlugin */
		$aclPlugin = $this->createMock(\Sabre\DAVACL\Plugin::class);

		/** @var Plugin|MockObject $schedulingPlugin */
		$schedulingPlugin = $this->createMock(Plugin::class);
		$iTipMessage = $this->getITipMessage($message);
		$iTipMessage->recipient = 'mailto:lewis@stardew-tent-living.com';

		$server = $this->createMock(Server::class);
		$server->expects($this->any())
			->method('getPlugin')
			->willReturnMap([
				['auth', $authPlugin],
				['acl', $aclPlugin],
				['caldav-schedule', $schedulingPlugin]
			]);
		$server->expects(self::once())
			->method('emit');

		$invitationResponseServer = $this->createPartialMock(InvitationResponseServer::class, ['getServer', 'isExternalAttendee']);
		$invitationResponseServer->server = $server;
		$invitationResponseServer->expects($this->any())
			->method('getServer')
			->willReturn($server);
		$invitationResponseServer->expects(self::once())
			->method('isExternalAttendee')
			->willReturn(false);

		$calendarImpl = $this->getMockBuilder(CalendarImpl::class)
			->setConstructorArgs([$this->calendar, $this->calendarInfo, $this->backend])
			->onlyMethods(['getInvitationResponseServer'])
			->getMock();
		$calendarImpl->expects($this->once())
			->method('getInvitationResponseServer')
			->willReturn($invitationResponseServer);

		$calendarImpl->handleIMipMessage('filename.ics', $message);
	}

	public function testHandleImipMessageNoCalendarUri(): void {
		/** @var CustomPrincipalPlugin|MockObject $authPlugin */
		$authPlugin = $this->createMock(CustomPrincipalPlugin::class);
		$authPlugin->expects(self::once())
			->method('setCurrentPrincipal')
			->with($this->calendar->getPrincipalURI());
		unset($this->calendarInfo['uri']);

		/** @var Plugin|MockObject $schedulingPlugin */
		$schedulingPlugin = $this->createMock(Plugin::class);

		/** @var \Sabre\DAVACL\Plugin|MockObject $schedulingPlugin */
		$aclPlugin = $this->createMock(\Sabre\DAVACL\Plugin::class);

		$server
			= $this->createMock(Server::class);
		$server->expects($this->any())
			->method('getPlugin')
			->willReturnMap([
				['auth', $authPlugin],
				['acl', $aclPlugin],
				['caldav-schedule', $schedulingPlugin]
			]);
		$server->expects(self::never())
			->method('emit');

		$invitationResponseServer = $this->createPartialMock(InvitationResponseServer::class, ['getServer']);
		$invitationResponseServer->server = $server;
		$invitationResponseServer->expects($this->any())
			->method('getServer')
			->willReturn($server);

		$calendarImpl = $this->getMockBuilder(CalendarImpl::class)
			->setConstructorArgs([$this->calendar, $this->calendarInfo, $this->backend])
			->onlyMethods(['getInvitationResponseServer'])
			->getMock();
		$calendarImpl->expects($this->once())
			->method('getInvitationResponseServer')
			->willReturn($invitationResponseServer);

		$message = <<<EOF
BEGIN:VCALENDAR
PRODID:-//Nextcloud/Nextcloud CalDAV Server//EN
METHOD:REPLY
VERSION:2.0
BEGIN:VEVENT
ATTENDEE;PARTSTAT=ACCEPTED:mailto:lewis@stardew-tent-living.com
ORGANIZER:mailto:pierre@generalstore.com
UID:aUniqueUid
SEQUENCE:2
REQUEST-STATUS:2.0;Success
END:VEVENT
END:VCALENDAR
EOF;

		$this->expectException(CalendarException::class);
		$calendarImpl->handleIMipMessage('filename.ics', $message);
	}

	private function getITipMessage($calendarData): Message {
		$iTipMessage = new Message();
		/** @var VCalendar $vObject */
		$vObject = Reader::read($calendarData);
		/** @var VEvent $vEvent */
		$vEvent = $vObject->{'VEVENT'};
		$orgaizer = $vEvent->{'ORGANIZER'}->getValue();
		$attendee = $vEvent->{'ATTENDEE'}->getValue();

		$iTipMessage->method = $vObject->{'METHOD'}->getValue();
		$iTipMessage->recipient = $orgaizer;
		$iTipMessage->sender = $attendee;
		$iTipMessage->uid = isset($vEvent->{'UID'}) ? $vEvent->{'UID'}->getValue() : '';
		$iTipMessage->component = 'VEVENT';
		$iTipMessage->sequence = isset($vEvent->{'SEQUENCE'}) ? (int)$vEvent->{'SEQUENCE'}->getValue() : 0;
		$iTipMessage->message = $vObject;
		return $iTipMessage;
	}

	protected function mockExportGenerator(): Generator {
		foreach ($this->mockExportCollection as $entry) {
			yield $entry;
		}
	}

	public function testExport(): void {
		// Arrange
		// construct calendar with a 1 hour event and same start/end time zones
		$vCalendar = new VCalendar();
		/** @var VEvent $vEvent */
		$vEvent = $vCalendar->add('VEVENT', []);
		$vEvent->UID->setValue('96a0e6b1-d886-4a55-a60d-152b31401dcc');
		$vEvent->add('DTSTART', '20240701T080000', ['TZID' => 'America/Toronto']);
		$vEvent->add('DTEND', '20240701T090000', ['TZID' => 'America/Toronto']);
		$vEvent->add('SUMMARY', 'Test Recurrence Event');
		$vEvent->add('ORGANIZER', 'mailto:organizer@testing.com', ['CN' => 'Organizer']);
		$vEvent->add('ATTENDEE', 'mailto:attendee1@testing.com', [
			'CN' => 'Attendee One',
			'CUTYPE' => 'INDIVIDUAL',
			'PARTSTAT' => 'NEEDS-ACTION',
			'ROLE' => 'REQ-PARTICIPANT',
			'RSVP' => 'TRUE'
		]);
		// construct data store return
		$this->mockExportCollection[] = [
			'id' => 1,
			'calendardata' => $vCalendar->serialize()
		];
		$this->backend->expects($this->once())
			->method('exportCalendar')
			->with(1, $this->backend::CALENDAR_TYPE_CALENDAR, null)
			->willReturn($this->mockExportGenerator());

		// Act
		foreach ($this->calendarImpl->export(null) as $entry) {
			$exported[] = $entry;
		}

		// Assert
		$this->assertCount(1, $exported, 'Invalid exported items count');
	}

}
