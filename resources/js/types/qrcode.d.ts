// The part of the `qrcode` npm package the kiosk hold screen uses (30, ASSETS §1.6). The
// package ships no types and @types/qrcode is not on the dependency list (TRD §2).
declare module 'qrcode' {
    interface QRCodeToCanvasOptions {
        width?: number;
        margin?: number;
        color?: { dark?: string; light?: string };
    }

    const QRCode: {
        toCanvas(canvas: HTMLCanvasElement, text: string, options?: QRCodeToCanvasOptions): Promise<void>;
    };

    export default QRCode;
}
