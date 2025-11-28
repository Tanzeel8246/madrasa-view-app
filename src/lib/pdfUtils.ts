import jsPDF from "jspdf";
import html2canvas from "html2canvas";

export const generatePDF = async (
  title: string,
  headers: string[],
  data: any[][],
  filename: string,
  isUrdu: boolean = false,
  tableId?: string
) => {
  // If tableId is provided and isUrdu is true, use html2canvas for better Urdu support
  if (isUrdu && tableId) {
    const tableElement = document.querySelector(`#${tableId}`);
    if (tableElement) {
      await generatePDFFromHTML(title, filename, tableElement as HTMLElement, isUrdu);
      return;
    }
  }

  // Fallback to jsPDF for non-Urdu or when table element not found
  const doc = new jsPDF({
    orientation: "landscape",
    unit: "mm",
    format: "a4",
  });

  doc.setFont("helvetica");
  doc.setFontSize(16);

  const pageWidth = doc.internal.pageSize.getWidth();
  const titleWidth = doc.getTextWidth(title);
  const titleX = (pageWidth - titleWidth) / 2;
  doc.text(title, titleX, 15);

  // Create a simple table structure
  const tableHtml = `
    <table style="width: 100%; border-collapse: collapse; font-family: 'Noto Nastaliq Urdu', Arial;">
      <thead>
        <tr style="background: #3b82f6; color: white;">
          ${headers.map(h => `<th style="border: 1px solid #ddd; padding: 12px; text-align: ${isUrdu ? 'right' : 'left'};">${h}</th>`).join('')}
        </tr>
      </thead>
      <tbody>
        ${data.map((row, i) => `
          <tr style="background: ${i % 2 === 0 ? '#f5f7fa' : 'white'};">
            ${row.map(cell => `<td style="border: 1px solid #ddd; padding: 12px; text-align: ${isUrdu ? 'right' : 'left'};">${cell}</td>`).join('')}
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;

  const tempDiv = document.createElement('div');
  tempDiv.innerHTML = tableHtml;
  tempDiv.style.direction = isUrdu ? 'rtl' : 'ltr';
  tempDiv.style.position = 'fixed';
  tempDiv.style.top = '-10000px';
  tempDiv.style.left = '-10000px';
  document.body.appendChild(tempDiv);

  try {
    const canvas = await html2canvas(tempDiv, {
      scale: 2,
      logging: false,
      useCORS: true,
    });

    const imgData = canvas.toDataURL('image/png');
    const imgWidth = pageWidth - 20;
    const imgHeight = (canvas.height * imgWidth) / canvas.width;

    doc.addImage(imgData, 'PNG', 10, 25, imgWidth, imgHeight);

    const dateStr = new Date().toLocaleDateString();
    doc.setFontSize(8);
    doc.text(`Generated on: ${dateStr}`, 10, doc.internal.pageSize.getHeight() - 10);

    doc.save(filename);
  } finally {
    document.body.removeChild(tempDiv);
  }
};

const generatePDFFromHTML = async (
  title: string,
  filename: string,
  tableElement: HTMLElement,
  isUrdu: boolean
) => {
  const doc = new jsPDF({
    orientation: "landscape",
    unit: "mm",
    format: "a4",
  });

  const currentDate = new Date().toLocaleDateString("ur-PK", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });

  // Create a container with title and table
  const container = document.createElement('div');
  container.style.direction = isUrdu ? 'rtl' : 'ltr';
  container.style.fontFamily = "'Noto Nastaliq Urdu', Arial, sans-serif";
  container.style.padding = '20px';
  container.style.background = 'white';
  container.style.position = 'fixed';
  container.style.top = '-10000px';
  container.style.left = '-10000px';
  container.style.width = '1200px';

  container.innerHTML = `
    <div style="text-align: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 3px solid #3b82f6;">
      <h1 style="font-size: 24px; color: #1e293b; margin-bottom: 10px;">${title}</h1>
      <div style="font-size: 14px; color: #64748b;">تاریخ: ${currentDate}</div>
    </div>
    ${tableElement.outerHTML}
  `;

  document.body.appendChild(container);

  try {
    const canvas = await html2canvas(container, {
      scale: 2,
      logging: false,
      useCORS: true,
      backgroundColor: '#ffffff',
    });

    const imgData = canvas.toDataURL('image/png');
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const imgWidth = pageWidth - 20;
    const imgHeight = (canvas.height * imgWidth) / canvas.width;

    // Handle multiple pages if content is too long
    let heightLeft = imgHeight;
    let position = 10;

    doc.addImage(imgData, 'PNG', 10, position, imgWidth, imgHeight);
    heightLeft -= pageHeight;

    while (heightLeft >= 0) {
      position = heightLeft - imgHeight;
      doc.addPage();
      doc.addImage(imgData, 'PNG', 10, position, imgWidth, imgHeight);
      heightLeft -= pageHeight;
    }

    doc.save(filename);
  } finally {
    document.body.removeChild(container);
  }
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
