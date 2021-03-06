<?php
require_once(dirname(__FILE__) . '/../lib/classes/Imap.php');
require_once(dirname(__FILE__) . '/../lib/utils.php');

if (version_compare(phpversion(), '5.3.2', '<')) {
	printf("ERROR: requires at least PHP version 5.3.2 (this is version %s)\n", phpversion());
	die();
}

if (2 > $argc) {
	printf("usage: php %s <confFile> [<option>]\n", basename($argv[0]));
	printf(" -test   perform a test run with no changes made (this will force a read-only\n");
	printf("         connection to the source and will not open the destination at all)\n");
	printf(" -info   check connection to source and show information about folders, i.e.\n");
	printf("         show name and number of folder and count messages\n");
	die();
}
else {
	$confFile = $argv[1];

	if (file_exists($confFile)) {
		$conf = json_decode(file_get_contents($confFile), true);

		if (!is_array($conf) || !isset($conf['src']) || !isset($conf['dst'])) {
			printf("ERROR: invalid/incomplete configuration in '%s'\n", $confFile);
			die();
		}
	}
	else {
		printf("ERROR: configuration file not found: %s\n", $confFile);
		die();
	}

	$args = $argv;
	array_shift($args);
	array_shift($args);

	$validArgs = array('-test', '-info');
	$invalidArgs = array_diff($args, $validArgs);
	if (!empty($invalidArgs)) {
		printf("ERROR: invalid option(s): %s\n", implode(', ', $invalidArgs));
		die();
	}
}

if (in_array('-test', $args)) {
	$testRun = true;

	printf("=== TEST RUN ===\n");
	printf("\n");

	$conf['src']['readOnly'] = true;
}
else {
	$testRun = false;
}

printf("*** opening source\n");
$src = new Imap($conf['src']);
printf('    connecting via \'%s\'...', $src->getMailbox());
$_ = $src->connect();
printf(" %s\n", test($_));
if (!$src->isConnected()) {
	printf(">>> error opening source\n");
	die();
}
else {
	printf(">>> source is ready\n");
}

printf("\n");

printf("*** opening destination\n");
$dst = new Imap($conf['dst']);
printf('    connecting via \'%s\'...', $dst->getMailbox());
if (!$testRun && !in_array('-info', $args)) {
	$_ = $dst->connect();
	printf(" %s\n", test($_));
	if (!$dst->isConnected()) {
		printf(">>> error opening destination\n");
		die();
	} else {
		printf(">>> destination is ready\n");
	}
}
else {
	printf(" SKIPPED\n");
}

printf("\n");

printf('*** Counting total source folders...');
$srcFolders = $src->getSubFolders('', '*');
printf(" %s\n", test(is_array($srcFolders)));
if (!is_array($srcFolders)) {
	printf(">>> error counting total source folders\n");
	die();
}
$srcFoldersCount = count($srcFolders);
printf(">>> %d total source folder(s) found\n", $srcFoldersCount);
if (0 == $srcFoldersCount) {
	printf(">>> nothing to do");
	die();
}

printf("\n");

printf('*** counting total source messages...');
$srcFolderMessagesCounts = array();
$srcMessagesCount = 0;
$srcFolderNum = 0;
foreach ($srcFolders as $srcFolder) {
	$srcFolderNum++;

	printf("\n");
	printf("    ... (f:%d/%d) '%s'\n", $srcFolderNum, $srcFoldersCount, utf8_encode($srcFolder));

	printf('        opening source folder...');
	$_ = $src->openFolder($srcFolder);
	printf(" %s\n", test($_));
	if (!$_) {
		continue;
	}

	printf('        counting source folder messages...');
	$srcFolderMessagesCount = $src->getFolderMessagesCount();
	printf(" %d source folder message(s) found\n", $srcFolderMessagesCount);

	$srcFolderMessagesCounts[$srcFolderNum] = $srcFolderMessagesCount;
	$srcMessagesCount += $srcFolderMessagesCount;
}
printf(">>> %d total source message(s) found\n", $srcMessagesCount);

if (in_array('-info', $args)) {
	die();
}

printf("\n");

printf("*** recursively copying folders and messages...");
$srcMessageNum = 0;
$srcFolderNum = 0;
foreach ($srcFolders as $srcFolder) {
	$srcFolderNum++;
	$srcFolderMessagesCount = $srcFolderMessagesCounts[$srcFolderNum];

	if (!$src->isOnlyFolderNum($srcFolderNum) ||
		$src->isBeforeStartFolderNum($srcFolderNum)) {
		$srcMessageNum += $srcFolderMessagesCount;

		continue;
	}

	printf("\n");
	printf("    ... (f:%d/%d) '%s'\n", $srcFolderNum, $srcFoldersCount, utf8_encode($srcFolder));

	printf('        opening source folder...');
	$_ = $src->openFolder($srcFolder);
	printf(" %s\n", test($_));
	if (!$_) {
		continue;
	}

	printf("        source folder message(s): %d\n", $srcFolderMessagesCount);

	printf('        destination folder:');
	$folderPath = $src->splitFolderPath($srcFolder);
	$dstFolder = $dst->joinFolderPath($folderPath, true);
	$dstFolder = $dst->getMappedFolder($dstFolder);
	$dstFolder = $dst->popFolder($dstFolder);
	$dstFolder = $dst->pushFolder($dstFolder);
	printf(" '%s'\n", utf8_encode($dstFolder));

	printf('        creating destination folder...');
	if (!$testRun) {
		$_ = $dst->createFolder($dstFolder);
		printf(" %s\n", test($_));
	}
	else {
		printf(" SKIPPED\n");
	}

	printf('        opening destination folder...');
	if (!$testRun) {
		$_ = $dst->openFolder($dstFolder);
		printf(" %s\n", test($_));
		if (!$_) {
			continue;
		}
	}
	else {
		printf(" SKIPPED\n");
	}

	for ($srcFolderMessageNum = 1; $srcFolderMessageNum <= $srcFolderMessagesCount; $srcFolderMessageNum++) {
		$srcMessageNum++;

		if (!$src->isOnlyFolderMessageNum($srcFolderNum, $srcFolderMessageNum) ||
			$src->isBeforeStartFolderMessageNum($srcFolderNum, $srcFolderMessageNum)) {
			continue;
		}

		printf("\n");
		printf('        ... (f:%d/%d;m:%d/%d,%d/%d)',
			$srcFolderNum,
			$srcFoldersCount,
			$srcFolderMessageNum,
			$srcFolderMessagesCount,
			$srcMessageNum,
			$srcMessagesCount
		);

		$srcMessageHeaderInfo = $src->getMessageHeaderInfo($srcFolderMessageNum);
		$srcMessageSubject = isset($srcMessageHeaderInfo->subject) ? $srcMessageHeaderInfo->subject : '';
		printf(" '%s'\n", mb_decode_mimeheader($srcMessageSubject));
		printf("            source message date is %s\n", $src->getMessageInternalDate($srcMessageHeaderInfo));

		$srcMessageSize = isset($srcMessageHeaderInfo->Size) ? $srcMessageHeaderInfo->Size: '?';
		printf('            loading source message (%s byte(s))...', $srcMessageSize);
		if (!$testRun) {
			$srcMessage = $src->loadMessage($srcFolderMessageNum);
			printf(" %d byte(s) read\n", strlen($srcMessage));
		}
		else {
			printf(" SKIPPED\n");
		}

		printf('            storing destination message...');
		if (!$testRun) {
			$_ = $dst->storeMessage($dstFolder, $srcMessage, $srcMessageHeaderInfo);
			printf(" %s\n", test($_));
		}
		else {
			printf(" SKIPPED\n");
		}
	}
}
