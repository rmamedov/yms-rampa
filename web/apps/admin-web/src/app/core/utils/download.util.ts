/** Збереження згенерованого CSV у файл. */
export function downloadTextFile(
  content: string,
  fileName: string,
  mime = 'text/csv;charset=utf-8',
): boolean {
  if (typeof document === 'undefined' || typeof URL?.createObjectURL !== 'function') {
    return false;
  }
  try {
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
    return true;
  } catch {
    return false;
  }
}

export function copyToClipboard(text: string): Promise<boolean> {
  if (typeof navigator === 'undefined' || !navigator.clipboard) {
    return Promise.resolve(false);
  }
  return navigator.clipboard
    .writeText(text)
    .then(() => true)
    .catch(() => false);
}
