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

export const printTable = (tableId: string, title?: string, isRTL: boolean = true) => {
  const table = document.querySelector(`#${tableId}`);
  if (!table) return;

  const currentDate = new Date().toLocaleDateString("ur-PK", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });

  // Create hidden iframe for reliable printing
  const iframe = document.createElement("iframe");
  iframe.style.position = "fixed";
  iframe.style.top = "-10000px";
  iframe.style.left = "-10000px";
  iframe.style.width = "0";
  iframe.style.height = "0";
  iframe.style.border = "none";
  
  document.body.appendChild(iframe);

  const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document;
  if (!iframeDoc) {
    document.body.removeChild(iframe);
    return;
  }

  const html = `
    <!DOCTYPE html>
    <html dir="${isRTL ? 'rtl' : 'ltr'}">
      <head>
        <meta charset="UTF-8">
        <title>${title || 'Print'}</title>
        <style>
          * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
          }
          
          body { 
            font-family: 'Noto Nastaliq Urdu', 'Arial', sans-serif;
            direction: ${isRTL ? 'rtl' : 'ltr'};
            padding: 20px;
            background: white;
          }
          
          .print-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid #3b82f6;
          }
          
          .print-header h1 {
            font-size: 24px;
            color: #1e293b;
            margin-bottom: 10px;
          }
          
          .print-header .date {
            font-size: 14px;
            color: #64748b;
          }
          
          table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0;
          }
          
          th, td { 
            border: 1px solid #e2e8f0; 
            padding: 12px 16px; 
            text-align: ${isRTL ? 'right' : 'left'};
            font-size: 14px;
          }
          
          th { 
            background-color: #3b82f6; 
            color: white; 
            font-weight: bold;
            font-size: 15px;
          }
          
          tbody tr:nth-child(even) { 
            background-color: #f8fafc; 
          }
          
          .print-footer {
            margin-top: 40px;
            padding-top: 15px;
            border-top: 2px solid #e2e8f0;
            text-align: center;
            font-size: 12px;
            color: #64748b;
          }
          
          @media print {
            body {
              padding: 0;
            }
            
            @page { 
              margin: 15mm;
              size: A4;
            }
          }
        </style>
      </head>
      <body>
        ${title ? `
          <div class="print-header">
            <h1>${title}</h1>
            <div class="date">تاریخ: ${currentDate}</div>
          </div>
        ` : ''}
        ${table.outerHTML}
        <div class="print-footer">
          Madrasah Management System - Printed on ${currentDate}
        </div>
      </body>
    </html>
  `;

  iframeDoc.open();
  iframeDoc.write(html);
  iframeDoc.close();

  // Wait for iframe to load content
  iframe.onload = () => {
    setTimeout(() => {
      try {
        iframe.contentWindow?.focus();
        iframe.contentWindow?.print();
        
        // Cleanup after print or user cancel
        setTimeout(() => {
          document.body.removeChild(iframe);
        }, 1000);
      } catch (error) {
        console.error("Print error:", error);
        document.body.removeChild(iframe);
      }
    }, 250);
  };
};
