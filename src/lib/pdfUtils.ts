import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";

export const generatePDF = (
  title: string,
  headers: string[],
  data: any[][],
  filename: string,
  isUrdu: boolean = false
) => {
  const doc = new jsPDF({
    orientation: "landscape",
    unit: "mm",
    format: "a4",
  });

  // Set font for Urdu support
  doc.setFont("helvetica");
  doc.setFontSize(16);

  // Add title
  const pageWidth = doc.internal.pageSize.getWidth();
  const titleWidth = doc.getTextWidth(title);
  const titleX = (pageWidth - titleWidth) / 2;
  doc.text(title, titleX, 15);

  // Add table
  autoTable(doc, {
    head: [headers],
    body: data,
    startY: 25,
    styles: {
      font: "helvetica",
      fontSize: 10,
      cellPadding: 3,
    },
    headStyles: {
      fillColor: [59, 130, 246],
      textColor: 255,
      fontStyle: "bold",
    },
    alternateRowStyles: {
      fillColor: [245, 247, 250],
    },
    margin: { top: 25, right: 10, bottom: 10, left: 10 },
  });

  // Add date at bottom
  const dateStr = new Date().toLocaleDateString();
  doc.setFontSize(8);
  doc.text(`Generated on: ${dateStr}`, 10, doc.internal.pageSize.getHeight() - 10);

  // Save PDF
  doc.save(filename);
};

export const printTable = (tableId: string) => {
  const printWindow = window.open("", "_blank");
  if (!printWindow) return;

  const table = document.querySelector(`#${tableId}`);
  if (!table) return;

  const html = `
    <!DOCTYPE html>
    <html>
      <head>
        <title>Print</title>
        <style>
          @media print {
            body { 
              font-family: Arial, sans-serif;
              direction: rtl;
            }
            table { 
              width: 100%; 
              border-collapse: collapse; 
              margin: 20px 0;
            }
            th, td { 
              border: 1px solid #ddd; 
              padding: 12px; 
              text-align: right;
            }
            th { 
              background-color: #3b82f6; 
              color: white; 
              font-weight: bold;
            }
            tr:nth-child(even) { 
              background-color: #f5f7fa; 
            }
            @page { 
              margin: 20mm; 
            }
          }
        </style>
      </head>
      <body>
        ${table.outerHTML}
        <script>
          window.onload = function() {
            window.print();
            window.onafterprint = function() {
              window.close();
            };
          };
        </script>
      </body>
    </html>
  `;

  printWindow.document.write(html);
  printWindow.document.close();
};
