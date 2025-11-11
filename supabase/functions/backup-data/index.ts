import { createClient } from 'https://esm.sh/@supabase/supabase-js@2.39.3';

const corsHeaders = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
};

interface BackupRequest {
  madrasahId: string;
  backupType?: 'manual' | 'auto' | 'pre_restore';
  notes?: string;
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

    const { madrasahId, backupType = 'manual', notes } = await req.json() as BackupRequest;

    if (!madrasahId) {
      throw new Error('Madrasah ID is required');
    }

    console.log(`Starting backup for madrasah: ${madrasahId}, type: ${backupType}`);

    // Fetch all data for the madrasah
    const tables = [
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

    const backupData: Record<string, any[]> = {};

    for (const table of tables) {
      const { data, error } = await supabaseClient
        .from(table)
        .select('*')
        .eq('madrasah_id', madrasahId);

      if (error) {
        console.error(`Error backing up ${table}:`, error);
        throw error;
      }

      backupData[table] = data || [];
      console.log(`Backed up ${data?.length || 0} records from ${table}`);
    }

    // Store backup metadata
    const { data: backup, error: backupError } = await supabaseClient
      .from('backups')
      .insert({
        madrasah_id: madrasahId,
        backup_type: backupType,
        backup_data: backupData,
        notes: notes || `${backupType} backup`,
      })
      .select()
      .single();

    if (backupError) {
      console.error('Error storing backup:', backupError);
      throw backupError;
    }

    console.log(`Backup completed successfully: ${backup.id}`);

    return new Response(
      JSON.stringify({
        success: true,
        backupId: backup.id,
        backupDate: backup.backup_date,
        message: 'Backup completed successfully',
      }),
      {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        status: 200,
      },
    );
  } catch (error) {
    console.error('Error in backup-data function:', error);
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
