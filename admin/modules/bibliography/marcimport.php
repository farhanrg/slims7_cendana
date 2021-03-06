<?php
/**
 * Copyright (C) 2009  Arie Nugraha (dicarve@yahoo.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

/* Item Import section */

// key to authenticate
define('INDEX_AUTH', '1');
// key to get full database access
define('DB_ACCESS', 'fa');

// main system configuration
require '../../../sysconfig.inc.php';
// IP based access limitation
require LIB.'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');
// start the session
require SB.'admin/default/session.inc.php';
require SIMBIO.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO.'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO.'simbio_FILE/simbio_file_upload.inc.php';

// privileges checking
$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_read) {
  die('<div class="errorBox">'.__('You are not authorized to view this section').'</div>');
}

// check if PEAR is installed
ob_start();
include 'System.php';
include 'File/MARC.php';
ob_end_clean();
if (!(class_exists('System') && class_exists('File_MARC'))) {
  die('<div class="errorBox">'.__('<a href="http://pear.php.net/index.php">PEAR</a>, <a href="http://pear.php.net/package/File_MARC">File_MARC</a>
    and <a href="http://pear.php.net/package/Structures_LinkedList/">Structures_LinkedList</a>
    packages need to be installed in order
    to import MARC record').'</div>');
}

// max chars in line for file operations
$max_chars = 1024*100;

if (isset($_POST['doImport'])) {
    // check for form validity
    if (!$_FILES['importFile']['name']) {
        utility::jsAlert(__('Please select the file to import!'));
        exit();
    } else {
      require MDLBS.'bibliography/biblio_utils.inc.php';
      require SIMBIO.'simbio_DB/simbio_dbop.inc.php';

      $start_time = time();
      // set PHP time limit
      set_time_limit(0);
      // set ob implicit flush
      ob_implicit_flush();
      // create upload object
      $upload = new simbio_file_upload();
      // get system temporary directory location
      $temp_dir = sys_get_temp_dir();
      $uploaded_file = $temp_dir.DS.$_FILES['importFile']['name'];
      // set max size
      $max_size = $sysconf['max_upload']*1024;
      $upload->setAllowableFormat(array('.mrc', '.xml', '.txt'));
      $upload->setMaxSize($max_size);
      $upload->setUploadDir($temp_dir);
      $upload_status = $upload->doUpload('importFile');
      if ($upload_status != UPLOAD_SUCCESS) {
          utility::jsAlert(__('Upload failed! File type not allowed or the size is more than').($sysconf['max_upload']/1024).' MB');
          exit();
      }
      $updated_row = 0;
      $marc_string = file_get_contents($uploaded_file);

      $marc_data = new File_MARC($marc_string, File_MARC::SOURCE_STRING);
      // create dbop object
      $sql_op = new simbio_dbop($dbs);

      $gmd_cache = array();
      $publ_cache = array();
      $place_cache = array();
      $lang_cache = array();
      $sor_cache = array();
      $author_cache = array();
      $subject_cache = array();
      $updated_row = '';

      while ($record = $marc_data->next()) {
        $data = array();
        $input_date = date('Y-m-d H:i:s');
        $data['input_date'] = $input_date;
        $data['last_update'] = $input_date;

        echo '<pre>';
        echo "\n";
        $title_fld = $record->getField('245');
        // Main title
        $title_main = $title_fld->getSubfields('a');
        // echo $title_main[0]->getData();
        $data['title'] = $title_main[0]->getData();
        // Sub title
        $subtitle = $title_fld->getSubfields('b');
        if (isset($subtitle[0])) {
          // echo 'Subtitle: '.$subtitle[0]->getData();
          $data['title'] .= $subtitle[0]->getData();
        }

        // Statement of Responsibility
        $sor = $title_fld->getSubfields('c');
        if (isset($sor[0])) {
          $data['title'] .= $sor[0]->getData();
          // echo "\n"; echo 'Statement of responsibility: '.$sor[0]->getData();
          $data['sor_id'] = utility::getID($dbs, 'mst_sor', 'sor_id', 'sor', $sor[0]->getData(), $sor_cache);
        }

        // Edition
        $ed_fld = $record->getField('250');
        if ($ed_fld) {
          $ed = $ed_fld->getSubfields('a');
          $ed2 = $ed_fld->getSubfields('b');
          if (isset($ed[0])) {
            // echo "\n"; echo 'Edition: '.$ed[0]->getData();
            $data['edition'] = $ed[0]->getData();
          }
          if (isset($ed2[0])) {
            // echo "\n"; echo 'Edition: '.$ed[0]->getData();
            $data['edition'] .= $ed2[0]->getData();
          }
        }

        // GMD
        $gmd = $title_fld->getSubFields('h');
        if (isset($gmd[0])) {
          // echo "\n"; echo 'GMD: '.$gmd[0]->getData();
          $data['gmd_id'] = utility::getID($dbs, 'mst_gmd', 'gmd_id', 'gmd_name', $gmd[0]->getData(), $gmd_cache);
        }

        // Identifier - ISBN
        $id_fld = $record->getField('020');
        if ($id_fld) {
          $isbn_issn = $id_fld->getSubfields('a');
          if (isset($isbn_issn[0])) {
            // echo "\n"; echo 'ISBN/ISSN: '.$isbn_issn[0]->getData();
            $data['isbn_issn'] = $isbn_issn[0]->getData();
          }
        }

        // Identifier - ISSN
        $id_fld = $record->getField('022');
        if ($id_fld) {
          echo "\n";
          $isbn_issn = $id_fld->getSubfields('a');
          if (isset($isbn_issn[0])) {
            // echo 'ISBN/ISSN: '.$isbn_issn[0]->getData();
            $data['isbn_issn'] = $isbn_issn[0]->getData();
          }
        }

        // Classification DDC
        $cls_fld = $record->getField('082');
        if ($cls_fld) {
          echo "\n";
          $classification = $cls_fld->getSubfields('a');
          if (isset($classification[0])) {
            // echo 'Classification: '.$classification[0]->getData();
            $data['classification'] = $classification[0]->getData();
          }
        }

        // Publication
        $pbl_fld = $record->getField('260');
        if ($pbl_fld) {
          $place = $pbl_fld->getSubfields('a');
          $publisher = $pbl_fld->getSubfields('b');
          $publish_year = $pbl_fld->getSubfields('c');
          if (isset($place[0])) {
            // echo "\n"; echo 'Publish place: '.$place[0]->getData();
            $data['publish_place_id'] = utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $place[0]->getData(), $place_cache);
          }
          if (isset($publisher[0])) {
            // echo 'Publisher: '.$publisher[0]->getData();
            $data['publisher_id'] = utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $publisher[0]->getData(), $publ_cache);
          }
          if (isset($publish_year[0])) {
            // echo 'Publish year: '.$publish_year[0]->getData();
            $data['publish_year'] = $publish_year[0]->getData();
          }
        }

        // Collation
        $clt_fld = $record->getField('300');
        if ($clt_fld) {
          $data['collation'] = '';
          $pages = $clt_fld->getSubfields('a');
          $ilus = $clt_fld->getSubfields('b');
          $dimension = $clt_fld->getSubfields('c');
          if (isset($pages[0])) {
            // echo 'Pages: '.$pages[0]->getData();
            $data['collation'] .= $pages[0]->getData();
          }
          if (isset($ilus[0])) {
            // echo 'Ilus.: '.$ilus[0]->getData();
            $data['collation'] .= $ilus[0]->getData();
          }
          if (isset($dimension[0])) {
            // echo 'Dimension: '.$dimension[0]->getData();
            $data['collation'] .= $dimension[0]->getData();
          }
        }

        // Series title
        $series_fld = $record->getField('440');
        if ($series_fld) {
          $series = $series_fld->getSubfields('a');
          if (isset($series[0])) {
            // echo "\n"; echo 'Series: '.$series[0]->getData();
            $data['series_title'] = $series[0]->getData();
          }
        }

        // Notes
        $notes_flds = $record->getFields('^5', true);
        if ($notes_flds) {
            $data['notes'] = '';
            // echo "\n"; echo 'Notes: ';
            foreach ($notes_flds as $note_fld) {
                if ($note_fld) {
                  $notes = $note_fld->getSubfields('a');
                  if (isset($notes[0])) {
                    $data['notes'] .= $notes[0]->getData();
                  }
                }
            }
        }

        // insert biblio data
        $sql_op->insert('biblio', $data);
        // echo '<p>'.$sql_op->error.'</p><p>&nbsp;</p>';
        $biblio_id = $sql_op->insert_id;
        if ($biblio_id < 1) {
            continue;
        }
        $updated_row++;

        // Subject
        $subject_flds = $record->getFields('650|651|648|655|656|657', true);
        if ($subject_flds) {
            // echo 'Subject: ';
            foreach ($subject_flds as $subj_fld) {
                if ($subj_fld) {
                  $subject = $subj_fld->getSubfields('a');
                  if (isset($subject[0])) {
                    // echo $subject[0]->getData();
                    $subject_type = 't';
                    $subject_id = getSubjectID($subject[0]->getData(), $subject_type, $subject_cache);
                    @$dbs->query("INSERT IGNORE INTO biblio_topic (biblio_id, topic_id, level) VALUES ($biblio_id, $subject_id, 1)");
                  }
                }
            }
        }

        // Main entry
        $me_fld = $record->getField('100');
        if ($me_fld) {
          $mes = $me_fld->getSubfields('a');
          if (isset($me[0])) {
            // echo 'Main entry: '.$me[0]->getData();
            $author_id = utility::getID($dbs, 'mst_author', 'author_id', 'author_name', $me[0]->getData(), $author_cache);
            @$dbs->query("INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES ($biblio_id, $author_id, 1)");
          }
        }

        // Author additional
        $author_flds = $record->getFields('700|710|711', true);
        if ($author_flds) {
            // echo 'Author: ';
            foreach ($author_flds as $tag => $auth_fld) {
                if ($tag == '710') {
                  $author_type = 'o';
                } else if ($tag == '711') {
                  $author_type = 'c';
                } else {
                  $author_type = 'p';
                }

                if ($auth_fld) {
                  $author = $auth_fld->getSubfields('a');
                  if (isset($author[0])) {
                    // echo $author[0]->getData();
                    $author_id = getAuthorID($author[0]->getData(), $author_type, $author_cache);
                    @$dbs->query("INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES ($biblio_id, $author_id, 1)");
                  }
                }
            }
        }

        echo '</pre>';
      }

      $end_time = time();
      $import_time_sec = $end_time-$start_time;
      utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'bibliography', 'Importing '.$updated_row.' MARC records from file : '.$_FILES['importFile']['name']);
      echo '<script type="text/javascript">'."\n";
      echo 'parent.$(\'#importInfo\').html(\'<strong>'.$updated_row.'</strong> records updated successfully to item database, from record <strong>'.$_POST['recordOffset'].' in '.$import_time_sec.' second(s)</strong>\');'."\n";
      echo 'parent.$(\'#importInfo\').css( {\'display\': \'block\'} );'."\n";
      echo '</script>';
      exit();
    }
}
?>
<fieldset class="menuBox">
<div class="menuBoxInner importIcon">
	<div class="per_title">
    	<h2><?php echo __('MARC Import tool'); ?></h2>
	</div>
	<div class="infoBox">
    <?php echo __('Import bibliographic records from MARC file. The file can be native MARC record format file (.mrc) or
        MARCXML XML file (.xml).
        You need to have PHP PEAR and PEAR\'s File_MARC package installed in your system.
        To convert native/legacy MARC file to MARCXML
        you can use <a class="notAJAX" href="http://www.loc.gov/standards/marcxml/marcxml.zip">MARCXML Toolkit</a>'); ?>
	</div>
</div>
</fieldset>
<div id="importInfo" class="infoBox" style="display: none;">&nbsp;</div><div id="importError" class="errorBox" style="display: none;">&nbsp;</div>
<?php
// create new instance
$form = new simbio_form_table_AJAX('mainForm', $_SERVER['PHP_SELF'], 'post');
$form->submit_button_attr = 'name="doImport" value="'.__('Import Now').'" class="button"';
// form table attributes
$form->table_attr = 'align="center" id="dataList" cellpadding="5" cellspacing="0"';
$form->table_header_attr = 'class="alterCell" style="font-weight: bold;"';
$form->table_content_attr = 'class="alterCell2"';

/* Form Element(s) */
// csv files
$str_input = simbio_form_element::textField('file', 'importFile');
$str_input .= ' Maximum '.$sysconf['max_upload'].' KB';
$form->addAnything(__('File To Import'), $str_input);
// text import
// $form->addTextField('textarea', 'MARCtext', __('MARC record text'), '', 'style="width: 100%; height: 500px;"');
// number of records to import
$form->addTextField('text', 'recordNum', __('Number of records to import (0 for all records)'), '0', 'style="width: 10%;"');
// output the form
echo $form->printOut();
