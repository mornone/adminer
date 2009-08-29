<?php
function tar_file($filename, $contents) {
	$return = pack("a100a8a8a8a12a12", $filename, 644, 0, 0, decoct(strlen($contents)), decoct(time()));
	$checksum = 8*32; // space for checksum itself
	for ($i=0; $i < strlen($return); $i++) {
		$checksum += ord($return{$i});
	}
	$return .= sprintf("%06o", $checksum) . "\0 ";
	return $return . str_repeat("\0", 512 - strlen($return)) . $contents . str_repeat("\0", 511 - (strlen($contents) + 511) % 512);
}

function dump_triggers($table, $style) {
	global $dbh;
	if ($_POST["format"] == "sql" && $style && $dbh->server_info >= 5) {
		$result = $dbh->query("SHOW TRIGGERS LIKE " . $dbh->quote(addcslashes($table, "%_")));
		if ($result->num_rows) {
			$s = "\nDELIMITER ;;\n";
			while ($row = $result->fetch_assoc()) {
				$s .= "\n" . ($style == 'CREATE+ALTER' ? "DROP TRIGGER IF EXISTS " . idf_escape($row["Trigger"]) . ";;\n" : "")
				. "CREATE TRIGGER " . idf_escape($row["Trigger"]) . " $row[Timing] $row[Event] ON " . idf_escape($row["Table"]) . " FOR EACH ROW\n$row[Statement];;\n";
			}
			dump("$s\nDELIMITER ;\n");
		}
	}
}

if ($_POST) {
	$ext = dump_headers((strlen($_GET["dump"]) ? $_GET["dump"] : DB), (!strlen(DB) || count((array) $_POST["tables"] + (array) $_POST["data"]) > 1));
	if ($_POST["format"] == "sql") {
		dump("SET NAMES utf8;
SET foreign_key_checks = 0;
SET time_zone = " . $dbh->quote($dbh->result($dbh->query("SELECT @@time_zone"))) . ";
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

");
	}
	
	$style = $_POST["db_style"];
	foreach ((strlen(DB) ? array(DB) : (array) $_POST["databases"]) as $db) {
		if ($dbh->select_db($db)) {
			if ($_POST["format"] == "sql" && ereg('CREATE', $style) && ($result = $dbh->query("SHOW CREATE DATABASE " . idf_escape($db)))) {
				if ($style == "DROP+CREATE") {
					dump("DROP DATABASE IF EXISTS " . idf_escape($db) . ";\n");
				}
				$create = $dbh->result($result, 1);
				dump(($style == "CREATE+ALTER" ? preg_replace('~^CREATE DATABASE ~', '\\0IF NOT EXISTS ', $create) : $create) . ";\n");
			}
			if ($style && $_POST["format"] == "sql") {
				dump(($style == "CREATE+ALTER" ? "SET @adminer_alter = '';\n" : "") . "USE " . idf_escape($db) . ";\n\n");
				$out = "";
				if ($dbh->server_info >= 5) {
					foreach (array("FUNCTION", "PROCEDURE") as $routine) {
						$result = $dbh->query("SHOW $routine STATUS WHERE Db = " . $dbh->quote($db));
						while ($row = $result->fetch_assoc()) {
							$out .= ($style != 'DROP+CREATE' ? "DROP $routine IF EXISTS " . idf_escape($row["Name"]) . ";;\n" : "")
							. $dbh->result($dbh->query("SHOW CREATE $routine " . idf_escape($row["Name"])), 2) . ";;\n\n";
						}
					}
				}
				if ($dbh->server_info >= 5.1) {
					$result = $dbh->query("SHOW EVENTS");
					while ($row = $result->fetch_assoc()) {
						$out .= ($style != 'DROP+CREATE' ? "DROP EVENT IF EXISTS " . idf_escape($row["Name"]) . ";;\n" : "")
						. $dbh->result($dbh->query("SHOW CREATE EVENT " . idf_escape($row["Name"])), 3) . ";;\n\n";
					}
				}
				if ($out) {
					dump("DELIMITER ;;\n\n$out" . "DELIMITER ;\n\n");
				}
			}
			
			if ($_POST["table_style"] || $_POST["data_style"]) {
				$views = array();
				foreach (table_status() as $row) {
					$table = (!strlen(DB) || in_array($row["Name"], (array) $_POST["tables"]));
					$data = (!strlen(DB) || in_array($row["Name"], (array) $_POST["data"]));
					if ($table || $data) {
						if (isset($row["Engine"])) {
							if ($ext == "tar") {
								ob_start();
							}
							dump_table($row["Name"], ($table ? $_POST["table_style"] : ""));
							if ($data) {
								dump_data($row["Name"], $_POST["data_style"]);
							}
							if ($table) {
								dump_triggers($row["Name"], $_POST["table_style"]);
							}
							if ($ext == "tar") {
								dump(tar_file((strlen(DB) ? "" : "$db/") . "$row[Name].csv", ob_get_clean()));
							} elseif ($_POST["format"] == "sql") {
								dump("\n");
							}
						} elseif ($_POST["format"] == "sql") {
							$views[] = $row["Name"];
						}
					}
				}
				foreach ($views as $view) {
					dump_table($view, $_POST["table_style"], true);
				}
				if ($ext == "tar") {
					dump(pack("x512"));
				}
			}
			
			if ($style == "CREATE+ALTER" && $_POST["format"] == "sql") {
				// drop old tables
				$query = "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()";
				dump("DELIMITER ;;
CREATE PROCEDURE adminer_alter (INOUT alter_command text) BEGIN
	DECLARE _table_name, _engine, _table_collation varchar(64);
	DECLARE _table_comment varchar(64);
	DECLARE done bool DEFAULT 0;
	DECLARE tables CURSOR FOR $query;
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
	OPEN tables;
	REPEAT
		FETCH tables INTO _table_name, _engine, _table_collation, _table_comment;
		IF NOT done THEN
			CASE _table_name");
$result = $dbh->query($query);
while ($row = $result->fetch_assoc()) {
	$comment = $dbh->quote($row["ENGINE"] == "InnoDB" ? preg_replace('~(?:(.+); )?InnoDB free: .*~', '\\1', $row["TABLE_COMMENT"]) : $row["TABLE_COMMENT"]);
	dump("
				WHEN " . $dbh->quote($row["TABLE_NAME"]) . " THEN
					" . (isset($row["ENGINE"]) ? "IF _engine != '$row[ENGINE]' OR _table_collation != '$row[TABLE_COLLATION]' OR _table_comment != $comment THEN
						ALTER TABLE " . idf_escape($row["TABLE_NAME"]) . " ENGINE=$row[ENGINE] COLLATE=$row[TABLE_COLLATION] COMMENT=$comment;
					END IF" : "BEGIN END") . ";");
}
dump("
				ELSE
					SET alter_command = CONCAT(alter_command, 'DROP TABLE `', REPLACE(_table_name, '`', '``'), '`;\\n');
			END CASE;
		END IF;
	UNTIL done END REPEAT;
	CLOSE tables;
END;;
DELIMITER ;
CALL adminer_alter(@adminer_alter);
DROP PROCEDURE adminer_alter;
");
			}
			if (in_array("CREATE+ALTER", array($style, $_POST["table_style"])) && $_POST["format"] == "sql") {
				dump("SELECT @adminer_alter;\n");
			}
		}
	}
	dump();
	exit;
}

page_header(lang('Export'), "", (strlen($_GET["export"]) ? array("table" => $_GET["export"]) : array()), DB);
?>

<form action="" method="post">
<table cellspacing="0">
<?php
$db_style = array('', 'USE', 'DROP+CREATE', 'CREATE');
$table_style = array('', 'DROP+CREATE', 'CREATE');
$data_style = array('', 'TRUNCATE+INSERT', 'INSERT', 'INSERT+UPDATE');
if ($dbh->server_info >= 5) {
	$db_style[] = 'CREATE+ALTER';
	$table_style[] = 'CREATE+ALTER';
}
echo "<tr><th>" . lang('Output') . "<td><input type='hidden' name='token' value='$token'>$dump_output\n"; // token is not needed but checked in bootstrap for all POST data
echo "<tr><th>" . lang('Format') . "<td>$dump_format\n";
echo "<tr><th>" . lang('Compression') . "<td>" . ($dump_compress ? $dump_compress : lang('None of the supported PHP extensions (%s) are available.', 'zlib, bz2')) . "\n";
echo "<tr><th>" . lang('Database') . "<td><select name='db_style'>" . optionlist($db_style, (strlen(DB) ? '' : 'CREATE')) . "</select>\n";
echo "<tr><th>" . lang('Tables') . "<td><select name='table_style'>" . optionlist($table_style, 'DROP+CREATE') . "</select>\n";
echo "<tr><th>" . lang('Data') . "<td><select name='data_style'>" . optionlist($data_style, 'INSERT') . "</select>\n";
?>
</table>
<p><input type="submit" value="<?php echo lang('Export'); ?>">

<table cellspacing="0">
<?php
if (strlen(DB)) {
	$checked = (strlen($_GET["dump"]) ? "" : " checked");
	echo "<thead><tr>";
	echo "<th style='text-align: left;'><label><input type='checkbox' id='check-tables'$checked onclick='form_check(this, /^tables\\[/);'>" . lang('Tables') . "</label>";
	echo "<th style='text-align: right;'><label>" . lang('Data') . "<input type='checkbox' id='check-data'$checked onclick='form_check(this, /^data\\[/);'></label>";
	echo "</thead>\n";
	$views = "";
	foreach (table_status() as $row) {
		$checked = (strlen($_GET["dump"]) && $row["Name"] != $_GET["dump"] ? '' : " checked");
		$print = "<tr><td><label><input type='checkbox' name='tables[]' value='" . h($row["Name"]) . "'$checked onclick=\"form_uncheck('check-tables');\">" . h($row["Name"]) . "</label>";
		if (!$row["Engine"]) {
			$views .= "$print\n";
		} else {
			echo "$print<td align='right'><label>" . ($row["Engine"] == "InnoDB" && $row["Rows"] ? lang('~ %s', $row["Rows"]) : $row["Rows"]) . "<input type='checkbox' name='data[]' value='" . h($row["Name"]) . "'$checked onclick=\"form_uncheck('check-data');\"></label>\n";
		}
	}
	echo $views;
} else {
	echo "<thead><tr><th style='text-align: left;'><label><input type='checkbox' id='check-databases' checked onclick='form_check(this, /^databases\\[/);'>" . lang('Database') . "</label></thead>\n";
	foreach (get_databases() as $db) {
		if (!information_schema($db)) {
			echo '<tr><td><label><input type="checkbox" name="databases[]" value="' . h($db) . '" checked onclick="form_uncheck(\'check-databases\');">' . h($db) . "</label>\n";
		}
	}
}
?>
</table>
</form>
