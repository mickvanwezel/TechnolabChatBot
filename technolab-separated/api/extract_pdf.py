#!/usr/bin/env python3
"""
extract_pdf.py
Extracts text from a PDF file and outputs it to stdout.
Usage: python3 extract_pdf.py /path/to/file.pdf
"""

import sys
import os

def extract(pdf_path: str) -> str:
    try:
        import PyPDF2
        text_parts = []
        with open(pdf_path, 'rb') as f:
            reader = PyPDF2.PdfReader(f)
            for page_num, page in enumerate(reader.pages):
                try:
                    t = page.extract_text()
                    if t:
                        text_parts.append(t.strip())
                except Exception:
                    pass
        return '\n'.join(text_parts)
    except ImportError:
        pass

    # Fallback: pdfminer
    try:
        from pdfminer.high_level import extract_text as pdfminer_extract
        return pdfminer_extract(pdf_path)
    except ImportError:
        pass

    return None


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("EXTRACT_ERROR:Geen bestandspad opgegeven.")
        sys.exit(1)

    path = sys.argv[1]
    if not os.path.exists(path):
        print(f"EXTRACT_ERROR:Bestand niet gevonden: {path}")
        sys.exit(1)

    result = extract(path)
    if result is None:
        print("EXTRACT_ERROR:Geen ondersteunde PDF-bibliotheek gevonden.")
        sys.exit(1)

    text = result.strip()
    if not text:
        print("EXTRACT_ERROR:PDF bevat geen leesbare tekst (mogelijk gescand).")
        sys.exit(1)

    print(text)
