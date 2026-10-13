// src/auth/auth.service.ts
import { Injectable, UnauthorizedException, ConflictException } from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { ConfigService } from '@nestjs/config';
import * as argon2 from 'argon2';
import { Response } from 'express';

import { User, Role } from '../user/user.entity';
import { RegisterDto, LoginDto } from './auth.dto';

const ACCESS_COOKIE = 'access_token';

@Injectable()
export class AuthService {
    constructor(
        @InjectRepository(User)
        private readonly userRepository: Repository<User>,
        private readonly jwtService: JwtService,
        private readonly configService: ConfigService,
    ) {}

    // ============ ГЕНЕРАЦИЯ ТОКЕНА ============
    private async generateToken(user: User) {
        const payload = {
            sub: String(user.id),
            email: user.email,
            role: user.role, // 👈 роль внутри токена
        };

        const accessToken = await this.jwtService.signAsync(payload, {
            expiresIn: this.configService.get('JWT_EXPIRES') || '1d',
        });

        return {
            accessToken,
            user: { id: user.id, email: user.email, name: user.name, role: user.role },
        };
    }

    // ============ УСТАНОВКА httpOnly COOKIE ============
    private setAuthCookie(res: Response, token: string) {
        const isProd = this.configService.get('NODE_ENV') === 'production';

        res.cookie(ACCESS_COOKIE, token, {
            httpOnly: true,        // 👈 JS не видит
            secure: isProd,        // HTTPS only в prod
            sameSite: 'lax',
            path: '/',
            maxAge: 24 * 60 * 60 * 1000, // 1 день
        });
    }

    private clearAuthCookie(res: Response) {
        res.clearCookie(ACCESS_COOKIE, { path: '/' });
    }

    // ============ РЕГИСТРАЦИЯ ============
    async register(dto: RegisterDto, res: Response) {
        const existing = await this.userRepository.findOne({ where: { email: dto.email } });
        if (existing) throw new ConflictException('Пользователь с таким email уже существует');

        const hashedPassword = await argon2.hash(dto.password);

        const user = this.userRepository.create({
            ...dto,
            password: hashedPassword,
            role: Role.USER,
        });
        await this.userRepository.save(user);

        const { accessToken, user: safeUser } = await this.generateToken(user);
        this.setAuthCookie(res, accessToken);
        return { user: safeUser };
    }

    // ============ ЛОГИН ============
    async login(dto: LoginDto, res: Response) {
        const user = await this.userRepository.findOne({ where: { email: dto.email } });
        if (!user) throw new UnauthorizedException('Неверный email или пароль');

        // argon2: СНАЧАЛА хеш, ПОТОМ пароль
        const valid = await argon2.verify(user.password, dto.password);
        if (!valid) throw new UnauthorizedException('Неверный email или пароль');

        const { accessToken, user: safeUser } = await this.generateToken(user);
        this.setAuthCookie(res, accessToken);
        return { user: safeUser };
    }

    // ============ LOGOUT ============
    logout(res: Response) {
        this.clearAuthCookie(res);
        return { success: true };
    }

    // ============ VALIDATE ============
    async validateUser(userId: string): Promise<User> {
        const user = await this.userRepository.findOne({ where: { id: userId } });
        if (!user) throw new UnauthorizedException('Пользователь не найден');
        return user; // 👈 весь объект, включая role
    }
}