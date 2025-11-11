import { ReactNode } from "react";
import { Table } from "@/components/ui/table";

interface ResponsiveTableProps {
  children: ReactNode;
  id?: string;
}

const ResponsiveTable = ({ children, id }: ResponsiveTableProps) => {
  return (
    <div className="w-full overflow-x-auto -mx-3 sm:mx-0">
      <div className="inline-block min-w-full align-middle">
        <div className="overflow-hidden border rounded-lg">
          <Table id={id}>{children}</Table>
        </div>
      </div>
    </div>
  );
};

export default ResponsiveTable;
