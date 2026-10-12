import { useEffect, useRef } from 'react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

export interface DeleteConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title?: string;
  description?: string;
  entityName?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  loading?: boolean;
  error?: string | null;
  onConfirm: () => void;
}

export function DeleteConfirmDialog({
  open,
  onOpenChange,
  title = 'Confirm Deletion',
  description,
  entityName,
  confirmLabel = 'Delete',
  cancelLabel = 'Cancel',
  loading = false,
  error,
  onConfirm,
}: DeleteConfirmDialogProps) {
  const isSubmittingRef = useRef(false);

  useEffect(() => {
    if (!loading) {
      isSubmittingRef.current = false;
    }
  }, [loading]);

  const handleConfirm = () => {
    if (loading || isSubmittingRef.current) return;
    isSubmittingRef.current = true;
    onConfirm();
  };

  return (
    <Dialog open={open} onOpenChange={loading ? () => {} : onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/50 dark:text-red-400">
              <AlertTriangle className="h-5 w-5" />
            </div>
            <div>
              <DialogTitle className="text-base font-semibold text-slate-900 dark:text-white">
                {title}
              </DialogTitle>
              {description ? (
                <DialogDescription className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                  {description}
                </DialogDescription>
              ) : entityName ? (
                <DialogDescription className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                  Are you sure you want to delete <strong className="font-semibold text-slate-800 dark:text-slate-200">&ldquo;{entityName}&rdquo;</strong>? This action cannot be undone.
                </DialogDescription>
              ) : (
                <DialogDescription className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                  Are you sure you want to delete this record? This action cannot be undone.
                </DialogDescription>
              )}
            </div>
          </div>
        </DialogHeader>

        {error && (
          <div className="mt-2 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
            {error}
          </div>
        )}

        <DialogFooter className="mt-4 gap-2 sm:gap-0">
          <Button
            type="button"
            variant="outline"
            disabled={loading}
            onClick={() => onOpenChange(false)}
          >
            {cancelLabel}
          </Button>
          <Button
            type="button"
            variant="destructive"
            disabled={loading}
            onClick={handleConfirm}
            className="bg-red-600 hover:bg-red-700 text-white inline-flex items-center gap-2"
          >
            {loading && <Loader2 className="h-4 w-4 animate-spin" />}
            {loading ? 'Deleting...' : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default DeleteConfirmDialog;
