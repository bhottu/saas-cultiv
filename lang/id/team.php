<?php

/*
 | Cultiv One - Indonesian strings for team membership.
 |
 | The distinction that matters here is soft delete vs hard delete. Removing a member
 | must read as reversible, and only the separate "delete permanently" action erases
 | the row — so the two copy sets are kept deliberately distinct.
 */

return [

    'Team' => 'Tim',
    'Removed members' => 'Anggota yang dihapus',
    'removed' => 'dihapus',

    // Soft delete (reversible)
    'Remove this team member?' => 'Hapus anggota tim ini?',
    'This user will no longer have access to this workspace.' => 'Pengguna ini tidak akan lagi memiliki akses ke workspace ini.',
    'Their accounts still exist — removing a member never deletes the user.' => 'Akun mereka tetap ada — menghapus anggota tidak pernah menghapus penggunanya.',
    'Removing…' => 'Menghapus…',
    'removed on :date' => 'dihapus pada :date',

    // Restore
    'Restore' => 'Pulihkan',
    'Restoring…' => 'Memulihkan…',
    'Deleted account' => 'Akun terhapus',

    // Hard delete (irreversible)
    'Delete Permanently' => 'Hapus Permanen',
    'Delete this membership permanently?' => 'Hapus keanggotaan ini secara permanen?',
    'This removed member will disappear from this workspace\'s list and the membership history will be erased. This action cannot be undone.' => 'Anggota yang telah dihapus ini akan hilang dari daftar workspace ini dan riwayat keanggotaannya akan dihapus. Tindakan ini tidak dapat dibatalkan.',
    'The user account itself is not deleted.' => 'Akun penggunanya sendiri tidak dihapus.',
    'Deleting…' => 'Menghapus…',

    // Member removal confirmation
    'This removed member will disappear from this workspace\'s list and the membership history will be erased. This action cannot be undone.' => 'Anggota yang dihapus ini akan hilang dari daftar workspace ini dan riwayat keanggotaannya akan dihapus. Tindakan ini tidak dapat dibatalkan.',

];