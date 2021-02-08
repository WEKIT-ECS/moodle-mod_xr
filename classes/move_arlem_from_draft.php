<?php

require_once(dirname(__FILE__). '/../../../config.php');
require_once($CFG->dirroot.'/mod/arete/classes/filemanager.php');

/**
 * This function is a copy of file_save_draft_area_files inside filelip.php
 * We replaced the global $USER->id with our userid from Unity
 * We will remove the file from user draft at the end
 *
 * @category files

 * @param int $filename the name of the file which will be deleted from user draft after cloned to plugin filearea
 * @param int $userid the userid which will be retrieved from Unity
 * @param int $draftitemid the id of the draft area to use. Normally obtained
 *      from file_get_submitted_draft_itemid('elementname') or similar.
 *      When set to -1 (probably, by a WebService) it won't process file merging, keeping the original state of the file area.
 * @param int $contextid This parameter and the next two identify the file area to save to.
 * @param string $component
 * @param string $filearea indentifies the file area.
 * @param int $itemid helps identifies the file area.
 * @param array $options area options (subdirs=>false, maxfiles=-1, maxbytes=0)
 * @param string $text some html content that needs to have embedded links rewritten
 *      to the @@PLUGINFILE@@ form for saving in the database.
 * @param bool $forcehttps force https urls.
 * @return string|null if $text was passed in, the rewritten $text is returned. Otherwise NULL.
 */
    
    function move_file_from_draft_area_to_arete($filename, $userid, $draftitemid, $contextid, $component, $filearea, $itemid, array $options=null, $text=null, $forcehttps=false) {


    $usercontext = context_user::instance($userid);
    $fs = get_file_storage();

    $options = (array)$options;
    if (!isset($options['subdirs'])) {
        $options['subdirs'] = false;
    }
    if (!isset($options['maxfiles'])) {
        $options['maxfiles'] = -1; // unlimited
    }
    if (!isset($options['maxbytes']) || $options['maxbytes'] == USER_CAN_IGNORE_FILE_SIZE_LIMITS) {
        $options['maxbytes'] = 0; // unlimited
    }
    if (!isset($options['areamaxbytes'])) {
        $options['areamaxbytes'] = FILE_AREA_MAX_BYTES_UNLIMITED; // Unlimited.
    }
    $allowreferences = true;
    if (isset($options['return_types']) && !($options['return_types'] & (FILE_REFERENCE | FILE_CONTROLLED_LINK))) {
        // we assume that if $options['return_types'] is NOT specified, we DO allow references.
        // this is not exactly right. BUT there are many places in code where filemanager options
        // are not passed to file_save_draft_area_files()
        $allowreferences = false;
    }

    // Check if the user has copy-pasted from other draft areas. Those files will be located in different draft
    // areas and need to be copied into the current draft area.
    $text = file_merge_draft_areas($draftitemid, $usercontext->id, $text, $forcehttps);

    // Check if the draft area has exceeded the authorised limit. This should never happen as validation
    // should have taken place before, unless the user is doing something nauthly. If so, let's just not save
    // anything at all in the next area.
    if (file_is_draft_area_limit_reached($draftitemid, $options['areamaxbytes'])) {
        return null;
    }

    $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id');
    $oldfiles   = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'id');

    // One file in filearea means it is empty (it has only top-level directory '.').
    if (count($draftfiles) > 1 || count($oldfiles) > 1) {
        // we have to merge old and new files - we want to keep file ids for files that were not changed
        // we change time modified for all new and changed files, we keep time created as is

        $newhashes = array();
        $filecount = 0;
        $context = context::instance_by_id($contextid, MUST_EXIST);
        foreach ($draftfiles as $file) {
            if (!$options['subdirs'] && $file->get_filepath() !== '/') {
                continue;
            }
            if (!$allowreferences && $file->is_external_file()) {
                continue;
            }
            if (!$file->is_directory()) {
                // Check to see if this file was uploaded by someone who can ignore the file size limits.
                $fileusermaxbytes = get_user_max_upload_file_size($context, $options['maxbytes'], 0, 0, $file->get_userid());
                if ($fileusermaxbytes != USER_CAN_IGNORE_FILE_SIZE_LIMITS
                        && ($options['maxbytes'] and $options['maxbytes'] < $file->get_filesize())) {
                    // Oversized file.
                    continue;
                }
                if ($options['maxfiles'] != -1 and $options['maxfiles'] <= $filecount) {
                    // more files - should not get here at all
                    continue;
                }
                $filecount++;
            }
            $newhash = $fs->get_pathname_hash($contextid, $component, $filearea, $itemid, $file->get_filepath(), $file->get_filename());
            $newhashes[$newhash] = $file;
        }

        // Loop through oldfiles and decide which we need to delete and which to update.
        // After this cycle the array $newhashes will only contain the files that need to be added.
        foreach ($oldfiles as $oldfile) {
            $oldhash = $oldfile->get_pathnamehash();
            if (!isset($newhashes[$oldhash])) {
                // delete files not needed any more - deleted by user
                $oldfile->delete();
                continue;
            }

            $newfile = $newhashes[$oldhash];
            // Now we know that we have $oldfile and $newfile for the same path.
            // Let's check if we can update this file or we need to delete and create.
            if ($newfile->is_directory()) {
                // Directories are always ok to just update.
            } else if (($source = @unserialize($newfile->get_source())) && isset($source->original)) {
                // File has the 'original' - we need to update the file (it may even have not been changed at all).
                $original = file_storage::unpack_reference($source->original);
                if ($original['filename'] !== $oldfile->get_filename() || $original['filepath'] !== $oldfile->get_filepath()) {
                    // Very odd, original points to another file. Delete and create file.
                    $oldfile->delete();
                    continue;
                }
            } else {
                // The same file name but absence of 'original' means that file was deteled and uploaded again.
                // By deleting and creating new file we properly manage all existing references.
                $oldfile->delete();
                continue;
            }

            // status changed, we delete old file, and create a new one
            if ($oldfile->get_status() != $newfile->get_status()) {
                // file was changed, use updated with new timemodified data
                $oldfile->delete();
                // This file will be added later
                continue;
            }

            // Updated author
            if ($oldfile->get_author() != $newfile->get_author()) {
                $oldfile->set_author($newfile->get_author());
            }
            // Updated license
            if ($oldfile->get_license() != $newfile->get_license()) {
                $oldfile->set_license($newfile->get_license());
            }

            // Updated file source
            // Field files.source for draftarea files contains serialised object with source and original information.
            // We only store the source part of it for non-draft file area.
            $newsource = $newfile->get_source();
            if ($source = @unserialize($newfile->get_source())) {
                $newsource = $source->source;
            }
            if ($oldfile->get_source() !== $newsource) {
                $oldfile->set_source($newsource);
            }

            // Updated sort order
            if ($oldfile->get_sortorder() != $newfile->get_sortorder()) {
                $oldfile->set_sortorder($newfile->get_sortorder());
            }

            // Update file timemodified
            if ($oldfile->get_timemodified() != $newfile->get_timemodified()) {
                $oldfile->set_timemodified($newfile->get_timemodified());
            }

            // Replaced file content
            if (!$oldfile->is_directory() &&
                    ($oldfile->get_contenthash() != $newfile->get_contenthash() ||
                    $oldfile->get_filesize() != $newfile->get_filesize() ||
                    $oldfile->get_referencefileid() != $newfile->get_referencefileid() ||
                    $oldfile->get_userid() != $newfile->get_userid())) {
                $oldfile->replace_file_with($newfile);
            }

            // unchanged file or directory - we keep it as is
            unset($newhashes[$oldhash]);
        }

        // Add fresh file or the file which has changed status
        // the size and subdirectory tests are extra safety only, the UI should prevent it
        foreach ($newhashes as $file) {
            $file_record = array('contextid'=>$contextid, 'component'=>$component, 'filearea'=>$filearea, 'itemid'=>$itemid, 'timemodified'=>time());
            if ($source = @unserialize($file->get_source())) {
                // Field files.source for draftarea files contains serialised object with source and original information.
                // We only store the source part of it for non-draft file area.
                $file_record['source'] = $source->source;
            }

            if ($file->is_external_file()) {
                $repoid = $file->get_repository_id();
                if (!empty($repoid)) {
                    $context = context::instance_by_id($contextid, MUST_EXIST);
                    $repo = repository::get_repository_by_id($repoid, $context);
                    if (!empty($options)) {
                        $repo->options = $options;
                    }
                    $file_record['repositoryid'] = $repoid;
                    // This hook gives the repo a place to do some house cleaning, and update the $reference before it's saved
                    // to the file store. E.g. transfer ownership of the file to a system account etc.
                    $reference = $repo->reference_file_selected($file->get_reference(), $context, $component, $filearea, $itemid);

                    $file_record['reference'] = $reference;
                }
            }

            $fs->create_file_from_storedfile($file_record, $file);
        }
    }


    if (is_null($text)) {
        return null;
    } else {
        deleteUserArlem($filename, $draftitemid, true, $userid);
        return file_rewrite_urls_to_pluginfile($text, $draftitemid, $forcehttps);
    }
}


