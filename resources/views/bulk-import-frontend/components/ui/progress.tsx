'use client';

import * as React from 'react';
import * as ProgressPrimitive from '@radix-ui/react-progress';
import { cn } from '@/lib/utils';

interface ProgressProps extends React.ComponentPropsWithoutRef<typeof ProgressPrimitive.Root> {
  value?: number;
  showLabel?: boolean;
  size?: 'sm' | 'default' | 'lg';
}

const Progress = React.forwardRef<React.ElementRef<typeof ProgressPrimitive.Root>, ProgressProps>(({ className, value = 0, showLabel = false, size = 'default', ...props }, ref) => {
  const heightClasses = { sm: 'h-2', default: 'h-4', lg: 'h-6' };
  return (
    <div className="w-full">
      <ProgressPrimitive.Root ref={ref} className={cn('relative w-full overflow-hidden rounded-full bg-secondary', heightClasses[size], className)} {...props}>
        <ProgressPrimitive.Indicator className="h-full w-full flex-1 bg-primary transition-all duration-300 ease-in-out" style={{ transform: `translateX(-${100 - (value || 0)}%)` }} />
      </ProgressPrimitive.Root>
      {showLabel && <div className="mt-1 text-xs text-muted-foreground text-right">{value?.toFixed(0)}%</div>}
    </div>
  );
});
Progress.displayName = ProgressPrimitive.Root.displayName;

export { Progress };
