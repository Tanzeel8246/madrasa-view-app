export type Json =
  | string
  | number
  | boolean
  | null
  | { [key: string]: Json | undefined }
  | Json[]

export type Database = {
  // Allows to automatically instantiate createClient with right options
  // instead of createClient<Database, { PostgrestVersion: 'XX' }>(URL, KEY)
  __InternalSupabase: {
    PostgrestVersion: "13.0.5"
  }
  public: {
    Tables: {
      attendance: {
        Row: {
          created_at: string | null
          date: string
          id: string
          madrasah_id: string | null
          notes: string | null
          status: string
          student_id: string
          time_slot: string | null
        }
        Insert: {
          created_at?: string | null
          date: string
          id?: string
          madrasah_id?: string | null
          notes?: string | null
          status: string
          student_id: string
          time_slot?: string | null
        }
        Update: {
          created_at?: string | null
          date?: string
          id?: string
          madrasah_id?: string | null
          notes?: string | null
          status?: string
          student_id?: string
          time_slot?: string | null
        }
        Relationships: [
          {
            foreignKeyName: "attendance_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "attendance_student_id_fkey"
            columns: ["student_id"]
            isOneToOne: false
            referencedRelation: "students"
            referencedColumns: ["id"]
          },
        ]
      }
      class_teachers: {
        Row: {
          class_id: string
          created_at: string
          id: string
          madrasah_id: string
          teacher_id: string
          updated_at: string
        }
        Insert: {
          class_id: string
          created_at?: string
          id?: string
          madrasah_id: string
          teacher_id: string
          updated_at?: string
        }
        Update: {
          class_id?: string
          created_at?: string
          id?: string
          madrasah_id?: string
          teacher_id?: string
          updated_at?: string
        }
        Relationships: [
          {
            foreignKeyName: "class_teachers_class_id_fkey"
            columns: ["class_id"]
            isOneToOne: false
            referencedRelation: "classes"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "class_teachers_teacher_id_fkey"
            columns: ["teacher_id"]
            isOneToOne: false
            referencedRelation: "teachers"
            referencedColumns: ["id"]
          },
        ]
      }
      classes: {
        Row: {
          created_at: string | null
          description: string | null
          id: string
          madrasah_id: string | null
          name: string
          teacher_id: string | null
          updated_at: string | null
        }
        Insert: {
          created_at?: string | null
          description?: string | null
          id?: string
          madrasah_id?: string | null
          name: string
          teacher_id?: string | null
          updated_at?: string | null
        }
        Update: {
          created_at?: string | null
          description?: string | null
          id?: string
          madrasah_id?: string | null
          name?: string
          teacher_id?: string | null
          updated_at?: string | null
        }
        Relationships: [
          {
            foreignKeyName: "classes_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "classes_teacher_id_fkey"
            columns: ["teacher_id"]
            isOneToOne: false
            referencedRelation: "teachers"
            referencedColumns: ["id"]
          },
        ]
      }
      expense: {
        Row: {
          amount: number
          category: string
          created_at: string | null
          date: string
          description: string | null
          id: string
          madrasah_id: string | null
          title: string
          updated_at: string | null
        }
        Insert: {
          amount: number
          category: string
          created_at?: string | null
          date?: string
          description?: string | null
          id?: string
          madrasah_id?: string | null
          title: string
          updated_at?: string | null
        }
        Update: {
          amount?: number
          category?: string
          created_at?: string | null
          date?: string
          description?: string | null
          id?: string
          madrasah_id?: string | null
          title?: string
          updated_at?: string | null
        }
        Relationships: [
          {
            foreignKeyName: "expense_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
        ]
      }
      fees: {
        Row: {
          amount: number
          created_at: string | null
          id: string
          madrasah_id: string | null
          month: number
          notes: string | null
          paid_amount: number | null
          payment_date: string | null
          status: string
          student_id: string
          updated_at: string | null
          year: number
        }
        Insert: {
          amount: number
          created_at?: string | null
          id?: string
          madrasah_id?: string | null
          month: number
          notes?: string | null
          paid_amount?: number | null
          payment_date?: string | null
          status: string
          student_id: string
          updated_at?: string | null
          year: number
        }
        Update: {
          amount?: number
          created_at?: string | null
          id?: string
          madrasah_id?: string | null
          month?: number
          notes?: string | null
          paid_amount?: number | null
          payment_date?: string | null
          status?: string
          student_id?: string
          updated_at?: string | null
          year?: number
        }
        Relationships: [
          {
            foreignKeyName: "fees_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "fees_student_id_fkey"
            columns: ["student_id"]
            isOneToOne: false
            referencedRelation: "students"
            referencedColumns: ["id"]
          },
        ]
      }
      income: {
        Row: {
          amount: number
          category: string
          created_at: string | null
          date: string
          description: string | null
          id: string
          madrasah_id: string | null
          title: string
          updated_at: string | null
        }
        Insert: {
          amount: number
          category: string
          created_at?: string | null
          date?: string
          description?: string | null
          id?: string
          madrasah_id?: string | null
          title: string
          updated_at?: string | null
        }
        Update: {
          amount?: number
          category?: string
          created_at?: string | null
          date?: string
          description?: string | null
          id?: string
          madrasah_id?: string | null
          title?: string
          updated_at?: string | null
        }
        Relationships: [
          {
            foreignKeyName: "income_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
        ]
      }
      learning_reports: {
        Row: {
          class_type: string
          created_at: string
          date: string
          id: string
          madrasah_id: string
          manzil_amount: string | null
          manzil_paras: string | null
          notes: string | null
          period_1: string | null
          period_2: string | null
          period_3: string | null
          period_4: string | null
          period_5: string | null
          period_6: string | null
          sabaq_amount: string | null
          sabaq_lines_pages: string | null
          sabaq_para_number: number | null
          sabqi_amount: string | null
          sabqi_para: number | null
          student_id: string
          updated_at: string
        }
        Insert: {
          class_type: string
          created_at?: string
          date?: string
          id?: string
          madrasah_id: string
          manzil_amount?: string | null
          manzil_paras?: string | null
          notes?: string | null
          period_1?: string | null
          period_2?: string | null
          period_3?: string | null
          period_4?: string | null
          period_5?: string | null
          period_6?: string | null
          sabaq_amount?: string | null
          sabaq_lines_pages?: string | null
          sabaq_para_number?: number | null
          sabqi_amount?: string | null
          sabqi_para?: number | null
          student_id: string
          updated_at?: string
        }
        Update: {
          class_type?: string
          created_at?: string
          date?: string
          id?: string
          madrasah_id?: string
          manzil_amount?: string | null
          manzil_paras?: string | null
          notes?: string | null
          period_1?: string | null
          period_2?: string | null
          period_3?: string | null
          period_4?: string | null
          period_5?: string | null
          period_6?: string | null
          sabaq_amount?: string | null
          sabaq_lines_pages?: string | null
          sabaq_para_number?: number | null
          sabqi_amount?: string | null
          sabqi_para?: number | null
          student_id?: string
          updated_at?: string
        }
        Relationships: []
      }
      loans: {
        Row: {
          amount: number
          created_at: string
          id: string
          loan_date: string
          madrasah_id: string | null
          notes: string | null
          paid_amount: number | null
          return_date: string | null
          status: string
          teacher_id: string
          updated_at: string
        }
        Insert: {
          amount: number
          created_at?: string
          id?: string
          loan_date?: string
          madrasah_id?: string | null
          notes?: string | null
          paid_amount?: number | null
          return_date?: string | null
          status?: string
          teacher_id: string
          updated_at?: string
        }
        Update: {
          amount?: number
          created_at?: string
          id?: string
          loan_date?: string
          madrasah_id?: string | null
          notes?: string | null
          paid_amount?: number | null
          return_date?: string | null
          status?: string
          teacher_id?: string
          updated_at?: string
        }
        Relationships: [
          {
            foreignKeyName: "loans_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "loans_teacher_id_fkey"
            columns: ["teacher_id"]
            isOneToOne: false
            referencedRelation: "teachers"
            referencedColumns: ["id"]
          },
        ]
      }
      madrasah: {
        Row: {
          address: string | null
          contact: string | null
          created_at: string
          email: string | null
          id: string
          logo_url: string | null
          madrasah_id: string
          name: string
          updated_at: string
        }
        Insert: {
          address?: string | null
          contact?: string | null
          created_at?: string
          email?: string | null
          id?: string
          logo_url?: string | null
          madrasah_id: string
          name: string
          updated_at?: string
        }
        Update: {
          address?: string | null
          contact?: string | null
          created_at?: string
          email?: string | null
          id?: string
          logo_url?: string | null
          madrasah_id?: string
          name?: string
          updated_at?: string
        }
        Relationships: []
      }
      notifications: {
        Row: {
          created_at: string
          id: string
          is_read: boolean
          madrasah_id: string
          message: string
          title: string
          type: string
          updated_at: string
        }
        Insert: {
          created_at?: string
          id?: string
          is_read?: boolean
          madrasah_id: string
          message: string
          title: string
          type: string
          updated_at?: string
        }
        Update: {
          created_at?: string
          id?: string
          is_read?: boolean
          madrasah_id?: string
          message?: string
          title?: string
          type?: string
          updated_at?: string
        }
        Relationships: []
      }
      profiles: {
        Row: {
          created_at: string
          full_name: string | null
          id: string
          madrasah_id: string
          role: string
          updated_at: string
          user_id: string
        }
        Insert: {
          created_at?: string
          full_name?: string | null
          id?: string
          madrasah_id: string
          role?: string
          updated_at?: string
          user_id: string
        }
        Update: {
          created_at?: string
          full_name?: string | null
          id?: string
          madrasah_id?: string
          role?: string
          updated_at?: string
          user_id?: string
        }
        Relationships: [
          {
            foreignKeyName: "profiles_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
        ]
      }
      salaries: {
        Row: {
          amount: number
          created_at: string
          id: string
          madrasah_id: string | null
          month: number
          notes: string | null
          payment_date: string | null
          status: string
          teacher_id: string
          updated_at: string
          year: number
        }
        Insert: {
          amount: number
          created_at?: string
          id?: string
          madrasah_id?: string | null
          month: number
          notes?: string | null
          payment_date?: string | null
          status?: string
          teacher_id: string
          updated_at?: string
          year: number
        }
        Update: {
          amount?: number
          created_at?: string
          id?: string
          madrasah_id?: string | null
          month?: number
          notes?: string | null
          payment_date?: string | null
          status?: string
          teacher_id?: string
          updated_at?: string
          year?: number
        }
        Relationships: [
          {
            foreignKeyName: "salaries_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "salaries_teacher_id_fkey"
            columns: ["teacher_id"]
            isOneToOne: false
            referencedRelation: "teachers"
            referencedColumns: ["id"]
          },
        ]
      }
      students: {
        Row: {
          address: string | null
          class: string
          class_id: string | null
          contact: string | null
          created_at: string | null
          date_of_birth: string | null
          father_name: string
          id: string
          madrasah_id: string | null
          name: string
          roll_number: string
          updated_at: string | null
        }
        Insert: {
          address?: string | null
          class: string
          class_id?: string | null
          contact?: string | null
          created_at?: string | null
          date_of_birth?: string | null
          father_name: string
          id?: string
          madrasah_id?: string | null
          name: string
          roll_number: string
          updated_at?: string | null
        }
        Update: {
          address?: string | null
          class?: string
          class_id?: string | null
          contact?: string | null
          created_at?: string | null
          date_of_birth?: string | null
          father_name?: string
          id?: string
          madrasah_id?: string | null
          name?: string
          roll_number?: string
          updated_at?: string | null
        }
        Relationships: [
          {
            foreignKeyName: "students_class_id_fkey"
            columns: ["class_id"]
            isOneToOne: false
            referencedRelation: "classes"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "students_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
        ]
      }
      teachers: {
        Row: {
          address: string | null
          contact: string | null
          created_at: string | null
          email: string | null
          id: string
          madrasah_id: string | null
          name: string
          qualification: string | null
          subject: string | null
          updated_at: string | null
        }
        Insert: {
          address?: string | null
          contact?: string | null
          created_at?: string | null
          email?: string | null
          id?: string
          madrasah_id?: string | null
          name: string
          qualification?: string | null
          subject?: string | null
          updated_at?: string | null
        }
        Update: {
          address?: string | null
          contact?: string | null
          created_at?: string | null
          email?: string | null
          id?: string
          madrasah_id?: string | null
          name?: string
          qualification?: string | null
          subject?: string | null
          updated_at?: string | null
        }
        Relationships: [
          {
            foreignKeyName: "teachers_madrasah_id_fkey"
            columns: ["madrasah_id"]
            isOneToOne: false
            referencedRelation: "madrasah"
            referencedColumns: ["id"]
          },
        ]
      }
    }
    Views: {
      [_ in never]: never
    }
    Functions: {
      get_user_madrasah_id: { Args: never; Returns: string }
    }
    Enums: {
      [_ in never]: never
    }
    CompositeTypes: {
      [_ in never]: never
    }
  }
}

type DatabaseWithoutInternals = Omit<Database, "__InternalSupabase">

type DefaultSchema = DatabaseWithoutInternals[Extract<keyof Database, "public">]

export type Tables<
  DefaultSchemaTableNameOrOptions extends
    | keyof (DefaultSchema["Tables"] & DefaultSchema["Views"])
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof (DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"] &
        DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Views"])
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? (DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"] &
      DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Views"])[TableName] extends {
      Row: infer R
    }
    ? R
    : never
  : DefaultSchemaTableNameOrOptions extends keyof (DefaultSchema["Tables"] &
        DefaultSchema["Views"])
    ? (DefaultSchema["Tables"] &
        DefaultSchema["Views"])[DefaultSchemaTableNameOrOptions] extends {
        Row: infer R
      }
      ? R
      : never
    : never

export type TablesInsert<
  DefaultSchemaTableNameOrOptions extends
    | keyof DefaultSchema["Tables"]
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"]
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"][TableName] extends {
      Insert: infer I
    }
    ? I
    : never
  : DefaultSchemaTableNameOrOptions extends keyof DefaultSchema["Tables"]
    ? DefaultSchema["Tables"][DefaultSchemaTableNameOrOptions] extends {
        Insert: infer I
      }
      ? I
      : never
    : never

export type TablesUpdate<
  DefaultSchemaTableNameOrOptions extends
    | keyof DefaultSchema["Tables"]
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"]
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"][TableName] extends {
      Update: infer U
    }
    ? U
    : never
  : DefaultSchemaTableNameOrOptions extends keyof DefaultSchema["Tables"]
    ? DefaultSchema["Tables"][DefaultSchemaTableNameOrOptions] extends {
        Update: infer U
      }
      ? U
      : never
    : never

export type Enums<
  DefaultSchemaEnumNameOrOptions extends
    | keyof DefaultSchema["Enums"]
    | { schema: keyof DatabaseWithoutInternals },
  EnumName extends DefaultSchemaEnumNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaEnumNameOrOptions["schema"]]["Enums"]
    : never = never,
> = DefaultSchemaEnumNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaEnumNameOrOptions["schema"]]["Enums"][EnumName]
  : DefaultSchemaEnumNameOrOptions extends keyof DefaultSchema["Enums"]
    ? DefaultSchema["Enums"][DefaultSchemaEnumNameOrOptions]
    : never

export type CompositeTypes<
  PublicCompositeTypeNameOrOptions extends
    | keyof DefaultSchema["CompositeTypes"]
    | { schema: keyof DatabaseWithoutInternals },
  CompositeTypeName extends PublicCompositeTypeNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[PublicCompositeTypeNameOrOptions["schema"]]["CompositeTypes"]
    : never = never,
> = PublicCompositeTypeNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[PublicCompositeTypeNameOrOptions["schema"]]["CompositeTypes"][CompositeTypeName]
  : PublicCompositeTypeNameOrOptions extends keyof DefaultSchema["CompositeTypes"]
    ? DefaultSchema["CompositeTypes"][PublicCompositeTypeNameOrOptions]
    : never

export const Constants = {
  public: {
    Enums: {},
  },
} as const
