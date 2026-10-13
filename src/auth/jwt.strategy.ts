import { Injectable, UnauthorizedException } from '@nestjs/common';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { ConfigService } from '@nestjs/config';
import { Request } from 'express';

import { AuthService } from './auth.service';
import { Role } from '../user/user.entity';

export interface JwtPayload {
    sub: string;
    email: string;
    role: Role;
}

@Injectable()
export class JwtStrategy extends PassportStrategy(Strategy) {
    constructor(
        private readonly configService: ConfigService,
        private readonly authService: AuthService,
    ) {
        const secret = configService.get<string>('JWT_SECRET');
        if (!secret) throw new Error('JWT_SECRET не задан в .env');

        super({
            jwtFromRequest: ExtractJwt.fromExtractors([
                (req: Request) => req?.cookies?.access_token ?? null,
            ]),
            ignoreExpiration: false,
            secretOrKey: secret,
        });
    }

    async validate(payload: JwtPayload) {
        const user = await this.authService.validateUser(payload.sub);
        if (!user) throw new UnauthorizedException();
        return {
            id: user.id,
            email: user.email,
            name: user.name,
            role: user.role,
        };
    }
}