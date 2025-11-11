import { createClient } from 'https://esm.sh/@supabase/supabase-js@2.39.3';

const corsHeaders = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
};

interface RestoreRequest {
  madrasahId: string;
  backupId: string;
}

Deno.serve(async (req) => {
  if (req.method === 'OPTIONS') {
    return new Response(null, { headers: corsHeaders });
  }

  try {
    const supabaseClient = createClient(
      Deno.env.get('SUPABASE_URL') ?? '',
      Deno.env.get('SUPABASE_SERVICE_ROLE_KEY') ?? '',
    );

    const { madrasahId, backupId } = await req.json() as RestoreRequest;

    if (!madrasahId || !backupId) {
      throw new Error('Madrasah ID and Backup ID are required');
    }

    console.log(`Starting restore for madrasah: ${madrasahId}, backup: ${backupId}`);

    // Step 1: Create pre-restore backup of current data
    console.log('Creating pre-restore backup...');
    const backupResponse = await fetch(
      `${Deno.env.get('SUPABASE_URL')}/functions/v1/backup-data`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${Deno.env.get('SUPABASE_ANON_KEY')}`,
        },
        body: JSON.stringify({
          madrasahId,
          backupType: 'pre_restore',
          notes: `Auto backup before restoring backup ${backupId}`,
        }),
      }
    );

    if (!backupResponse.ok) {
      throw new Error('Failed to create pre-restore backup');
    }

    const preRestoreBackup = await backupResponse.json();
    console.log(`Pre-restore backup created: ${preRestoreBackup.backupId}`);

    // Step 2: Fetch the backup data
    const { data: backup, error: fetchError } = await supabaseClient
      .from('backups')
      .select('backup_data')
      .eq('id', backupId)
      .eq('madrasah_id', madrasahId)
      .single();

    if (fetchError || !backup) {
      throw new Error('Backup not found');
    }

    const backupData = backup.backup_data as Record<string, any[]>;

    // Step 3: Delete current data (in reverse order for foreign keys)
    const tables = [
      'learning_reports',
      'loans',
      'salaries',
      'expense',
      'income',
      'fees',
      'attendance',
      'class_teachers',
      'classes',
      'teachers',
      'students',
    ];

    for (const table of tables) {
      const { error: deleteError } = await supabaseClient
        .from(table)
        .delete()
        .eq('madrasah_id', madrasahId);

      if (deleteError) {
        console.error(`Error deleting from ${table}:`, deleteError);
        throw deleteError;
      }

      console.log(`Deleted existing data from ${table}`);
    }

    // Step 4: Restore data (in order)
    const restoreTables = [
      'students',
      'teachers',
      'classes',
      'class_teachers',
      'attendance',
      'fees',
      'income',
      'expense',
      'salaries',
      'loans',
      'learning_reports',
    ];

    let totalRestored = 0;

    for (const table of restoreTables) {
      const tableData = backupData[table] || [];
      
      if (tableData.length > 0) {
        const { error: insertError } = await supabaseClient
          .from(table)
          .insert(tableData);

        if (insertError) {
          console.error(`Error restoring ${table}:`, insertError);
          throw insertError;
        }

        totalRestored += tableData.length;
        console.log(`Restored ${tableData.length} records to ${table}`);
      }
    }

    console.log(`Restore completed successfully. Total records restored: ${totalRestored}`);

    return new Response(
      JSON.stringify({
        success: true,
        preRestoreBackupId: preRestoreBackup.backupId,
        recordsRestored: totalRestored,
        message: 'Data restored successfully. A backup of your previous data was created.',
      }),
      {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        status: 200,
      },
    );
  } catch (error) {
    console.error('Error in restore-data function:', error);
    const errorMessage = error instanceof Error ? error.message : 'Unknown error occurred';
    return new Response(
      JSON.stringify({ error: errorMessage }),
      {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        status: 500,
      },
    );
  }
});
